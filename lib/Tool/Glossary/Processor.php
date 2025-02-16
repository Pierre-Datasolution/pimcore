<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Tool\Glossary;

use Pimcore\Cache;
use Pimcore\Http\Request\Resolver\DocumentResolver;
use Pimcore\Http\Request\Resolver\EditmodeResolver;
use Pimcore\Http\RequestHelper;
use Pimcore\Model\Document;
use Pimcore\Model\Glossary;
use Pimcore\Model\Site;
use Pimcore\Tool\DomCrawler;

/**
 * @internal
 */
class Processor
{
    /**
     * @var RequestHelper
     */
    private $requestHelper;

    /**
     * @var EditmodeResolver
     */
    private $editmodeResolver;

    /**
     * @var DocumentResolver
     */
    private $documentResolver;

    /**
     * @var array
     */
    private $blockedTags = [
        'a', 'script', 'style', 'code', 'pre', 'textarea', 'acronym',
        'abbr', 'option', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * @param RequestHelper $requestHelper
     * @param EditmodeResolver $editmodeResolver
     * @param DocumentResolver $documentResolver
     */
    public function __construct(
        RequestHelper $requestHelper,
        EditmodeResolver $editmodeResolver,
        DocumentResolver $documentResolver
    ) {
        $this->requestHelper = $requestHelper;
        $this->editmodeResolver = $editmodeResolver;
        $this->documentResolver = $documentResolver;
    }

    /**
     * Process glossary entries in content string
     *
     * @param string $content
     * @param array $options
     *
     * @return string
     */
    public function process(string $content, array $options): string
    {
        $data = $this->getData();
        if (empty($data)) {
            return $content;
        }

        $options = array_merge([
            'limit' => -1,
        ], $options);

        if ($this->editmodeResolver->isEditmode()) {
            return $content;
        }

        // why not using a simple str_ireplace(array(), array(), $subject) ?
        // because if you want to replace the terms "Donec vitae" and "Donec" you will get nested links, so the content
        // of the html must be reloaded every search term to ensure that there is no replacement within a blocked tag
        $html = new DomCrawler($content);
        $es = $html->filterXPath('//*[normalize-space(text())]');

        $tmpData = [
            'search' => [],
            'replace' => [],
        ];

        // get initial document from request (requested document, if it was a "document" request)
        $currentDocument = $this->documentResolver->getDocument();
        $currentUri = $this->requestHelper->getMainRequest()->getRequestUri();

        foreach ($data as $entry) {
            if ($currentDocument && $currentDocument instanceof Document) {
                // check if the current document is the target link (id check)
                if ($entry['linkType'] == 'internal' && $currentDocument->getId() == $entry['linkTarget']) {
                    continue;
                }

                // check if the current document is the target link (path check)
                if ($currentDocument->getFullPath() == rtrim($entry['linkTarget'], ' /')) {
                    continue;
                }
            }

            // check if the current URI is the target link (path check)
            if ($currentUri == rtrim($entry['linkTarget'], ' /')) {
                continue;
            }

            $tmpData['search'][] = $entry['search'];
            $tmpData['replace'][] = $entry['replace'];
        }

        $data = $tmpData;
        $data['count'] = array_fill(0, count($data['search']), 0);

        $es->each(function ($parentNode, $i) use ($options, $data) {
            /** @var DomCrawler|null $parentNode */
            $text = $parentNode->html();
            if (
                $parentNode instanceof DomCrawler &&
                !in_array((string)$parentNode->nodeName(), $this->blockedTags) &&
                strlen(trim($text))
            ) {
                $originalText = $text;
                if ($options['limit'] < 0) {
                    $text = preg_replace($data['search'], $data['replace'], $text);
                } else {
                    foreach ($data['search'] as $index => $search) {
                        if ($data['count'][$index] < $options['limit']) {
                            $limit = $options['limit'] - $data['count'][$index];
                            $text = preg_replace($search, $data['replace'][$index], $text, $limit, $count);
                            $data['count'][$index] += $count;
                        }
                    }
                }

                if ($originalText !== $text) {
                    $domNode = $parentNode->getNode(0);
                    $fragment = $domNode->ownerDocument->createDocumentFragment();
                    $fragment->appendXML($text);
                    $clone = $domNode->cloneNode();
                    $clone->appendChild($fragment);
                    $domNode->parentNode->replaceChild($clone, $domNode);
                }
            }
        });

        $result = $html->html();
        $html->clear();
        unset($html);

        return $result;
    }

    /**
     * @return array
     */
    private function getData(): array
    {
        $locale = $this->requestHelper->getMainRequest()->getLocale();
        if (!$locale) {
            return [];
        }

        $siteId = '';
        if (Site::isSiteRequest()) {
            $siteId = Site::getCurrentSite()->getId();
        }

        $cacheKey = 'glossary_' . $locale . '_' . $siteId;

        if (Cache\Runtime::isRegistered($cacheKey)) {
            return Cache\Runtime::get($cacheKey);
        }

        if (!$data = Cache::load($cacheKey)) {
            $list = new Glossary\Listing();
            $list->setCondition("(language = ? OR language IS NULL OR language = '') AND (site = ? OR site IS NULL OR site = '')", [$locale, $siteId]);
            $list->setOrderKey('LENGTH(`text`)', false);
            $list->setOrder('DESC');

            $data = $list->getDataArray();
            $data = $this->prepareData($data);

            Cache::save($data, $cacheKey, ['glossary'], null, 995);
            Cache\Runtime::set($cacheKey, $data);
        }

        return $data;
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function prepareData(array $data): array
    {
        $mappedData = [];

        // fix htmlentities issues
        $tmpData = [];
        foreach ($data as $d) {
            $text = htmlentities($d['text'], ENT_COMPAT, 'UTF-8');
            if ($d['text'] !== $text) {
                $td = $d;
                $td['text'] = $text;
                $tmpData[] = $td;
            }

            $tmpData[] = $d;
        }

        $data = $tmpData;

        // prepare data
        foreach ($data as $d) {
            if (!($d['link'] || $d['abbr'])) {
                continue;
            }

            $r = $d['text'];
            if ($d['abbr']) {
                $r = '<abbr class="pimcore_glossary" title="' . $d['abbr'] . '">' . $r . '</abbr>';
            }

            $linkType = '';
            $linkTarget = '';

            if ($d['link']) {
                $linkType = 'external';
                $linkTarget = $d['link'];

                if ((int)$d['link']) {
                    if ($doc = Document::getById($d['link'])) {
                        $d['link'] = $doc->getFullPath();

                        $linkType = 'internal';
                        $linkTarget = $doc->getId();
                    }
                }

                $r = '<a class="pimcore_glossary" href="' . $d['link'] . '">' . $r . '</a>';
            }

            // add PCRE delimiter and modifiers
            if ($d['exactmatch']) {
                $d['text'] = '/<a.*\/a>(*SKIP)(*FAIL)|(?<!\w)' . preg_quote($d['text'], '/') . '(?!\w)/';
            } else {
                $d['text'] = '/<a.*\/a>(*SKIP)(*FAIL)|' . preg_quote($d['text'], '/') . '/';
            }

            if (!$d['casesensitive']) {
                $d['text'] .= 'i';
            }

            $mappedData[] = [
                'replace' => $r,
                'search' => $d['text'],
                'linkType' => $linkType,
                'linkTarget' => $linkTarget,
            ];
        }

        return $mappedData;
    }
}
