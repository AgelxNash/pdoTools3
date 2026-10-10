<?php

namespace ModxPro\PdoTools\Support;

use ModxPro\PdoTools\CoreTools;
use MODX\Revolution\modX;

class Paginator
{
    /** @var modX $modx */
    public $modx;
    /** @var CoreTools $pdoTools */
    public $pdoTools;
    /** @var string $req_var */
    protected $req_var = '';


    /**
     * @param modX $modx
     * @param array $config
     */
    public function __construct(modX $modx, array $config = [])
    {
        $this->modx = $modx;
        $this->pdoTools = new CoreTools($modx, $config);
        $modx->lexicon->load('pdotools:pdopage');
    }


    /**
     * Loads javascript and styles for ajax pagination
     *
     */
    public function loadJsCss()
    {
        $assetsUrl = !empty($this->pdoTools->config('assetsUrl'))
            ? $this->pdoTools->config('assetsUrl')
            : MODX_ASSETS_URL . 'components/pdotools/';
        if ($css = trim($this->pdoTools->config('frontend_css'))) {
            $this->modx->regClientCSS(str_replace('[[+assetsUrl]]', $assetsUrl, $css));
        }
        if ($js = trim($this->pdoTools->config('frontend_js'))) {
            $this->modx->regClientScript(str_replace('[[+assetsUrl]]', $assetsUrl, $js));
        }
        $ajaxHistory = $this->pdoTools->config('ajaxHistory') === ''
            ? !in_array($this->pdoTools->config('ajaxMode'), ['scroll', 'button'])
            : !empty($this->pdoTools->config('ajaxHistory'));
        $limit = $this->pdoTools->config('limit') > $this->pdoTools->config('maxLimit')
            ? $this->pdoTools->config('maxLimit')
            : $this->pdoTools->config('limit');
        $moreChunk = $this->modx->getOption(
            'ajaxTplMore',
            $this->pdoTools->config(),
            '@INLINE <button class="btn btn-default btn-more">[[%pdopage_more]]</button>'
        );
        $moreTpl = $this->pdoTools->getChunk($moreChunk, ['limit' => $limit]);

        $hash = sha1(json_encode($this->pdoTools->config()));
        $_SESSION['pdoPage'][$hash] = $this->pdoTools->config();

        $config = [
            'wrapper' => $this->modx->getOption('ajaxElemWrapper', $this->pdoTools->config(), '#pdopage'),
            'rows' => $this->modx->getOption('ajaxElemRows', $this->pdoTools->config(), '#pdopage .rows'),
            'pagination' => $this->modx->getOption(
                'ajaxElemPagination',
                $this->pdoTools->config(),
                '#pdopage .pagination'
            ),
            'link' => $this->modx->getOption('ajaxElemLink', $this->pdoTools->config(), '#pdopage .pagination a'),
            'more' => $this->modx->getOption('ajaxElemMore', $this->pdoTools->config(), '#pdopage .btn-more'),
            'moreTpl' => $moreTpl,
            'mode' => $this->pdoTools->config('ajaxMode'),
            'history' => (int)$ajaxHistory,
            'pageVarKey' => $this->pdoTools->config('pageVarKey'),
            'pageLimit' => $limit,
            'assetsUrl' => $assetsUrl,
            'connectorUrl' => rtrim($assetsUrl, '/') . '/connector.php',
            'pageId' => $this->modx->resource->id,
            'hash' => $hash,
            'scrollTop' => (bool)$this->modx->getOption('scrollTop', $this->pdoTools->config(), true),
        ];

        if (empty($this->pdoTools->config('frontend_startup_js'))) {
            $this->modx->regClientStartupScript(
                '<script>pdoPage = {callbacks: {}, keys: {}, configs: {}};</script>',
                true
            );
        } else {
            $this->modx->regClientStartupScript(
                $this->pdoTools->getChunk($this->pdoTools->config('frontend_startup_js')),
                true
            );
        }

        if (empty($this->pdoTools->config('frontend_init_js'))) {
            /** @noinspection Annotator */
            $this->modx->regClientScript(
                '<script>pdoPage.initialize(' . json_encode($config) . ')</script>',
                true
            );
        } else {
            $this->modx->regClientScript(
                $this->pdoTools->getChunk($this->pdoTools->config('frontend_init_js'), [
                    'key' => $config['pageVarKey'],
                    'wrapper' => $config['wrapper'],
                    'config' => json_encode($config),
                ]),
                true
            );
        }
    }


    /**
     * Redirect user to the first page of pagination
     *
     * @param bool $isAjax
     *
     * @return string
     */
    public function redirectToFirst($isAjax = false)
    {
        unset($_GET[$this->pdoTools->config('pageVarKey')]);
        unset($_GET[$this->modx->getOption('request_param_alias', null, 'q')]);
        if (!$isAjax) {
            $this->modx->sendRedirect(
                $this->modx->makeUrl(
                    $this->modx->resource->id,
                    $this->modx->context->key,
                    $_GET,
                    'full'
                )
            );

            return '';
        }

        $_GET[$this->pdoTools->config('pageVarKey')] = 1;
        $_REQUEST = $_GET;

        return $this->pdoTools->runSnippet('pdoPage', $this->pdoTools->config());
    }


    /**
     * Returns current base url for pagination
     *
     * @return string $url
     */
    public function getBaseUrl()
    {
        if ($this->modx->getOption('friendly_urls')) {
            $q_var = $this->modx->getOption('request_param_alias', null, 'q');
            $q_val = $_REQUEST[$q_var] ?? '';
            $this->req_var = $q_var;

            $host = '';
            switch ($this->pdoTools->config('scheme')) {
                case 'full':
                    $host = $this->modx->getOption('site_url');
                    break;
                case 'abs':
                case 'absolute':
                    $host = $this->modx->getOption('base_url');
                    break;
                case 'https':
                case 'http':
                    $host = $this->pdoTools->config('scheme') . '://' . $this->modx->getOption('http_host') .
                        $this->modx->getOption('base_url');
                    break;
            }
            $url = $host . $q_val;
        } else {
            $id_var = $this->modx->getOption('request_param_id', null, 'id');
            $id_val = $_GET[$id_var] ?? $this->modx->getOption('site_start');
            $this->req_var = $id_var;

            $url = $this->modx->makeUrl($id_val, '', '', $this->pdoTools->config('scheme'));
        }

        return $url;
    }

    /**
     * Returns templates link for pagination
     *
     * @param string $url
     * @param int $page
     * @param string $tpl
     * @param int|null $current Current page (for isActive / isFirst / isLast)
     * @param int|null $pages Total pages
     * @param bool $skip Skip/ellipsis slot
     *
     * @return string $href
     */
    public function makePageLink($url = '', $page = 1, $tpl = '', $current = null, $pages = null, $skip = false)
    {
        if (empty($url)) {
            $url = $this->getBaseUrl();
        }

        $link = $pcre = '';
        if (!empty($this->pdoTools->config('pageLinkScheme'))) {
            $pls = $this->pdoTools->makePlaceholders([
                'pageVarKey' => $this->pdoTools->config('pageVarKey'),
                'page' => $page,
            ]);
            $link = str_replace($pls['pl'], $pls['vl'], $this->pdoTools->config('pageLinkScheme'));
            $pcre = preg_replace('#\d+#', '(\d+)', preg_replace('#\#.*#', '', $link));
        }

        $href = !empty($link)
            ? preg_replace('#' . preg_quote($pcre, '#') . '#', '', $url)
            : $url;
        if ($page > 1 || ($page === 1 && !empty($this->pdoTools->config('ajax')))) {
            if (!empty($link)) {
                $href .= $link;
            } else {
                $href .= strpos($href, '?') !== false
                    ? '&'
                    : '?';
                $href .= $this->pdoTools->config('pageVarKey') . '=' . $page;
            }
        }
        if (!empty($_GET)) {
            $request = $_GET;
            array_walk_recursive($request, function (&$item) {
                $item = rawurldecode($item);
            });
            unset($request[$this->req_var]);
            unset($request[$this->pdoTools->config('pageVarKey')]);

            if (!empty($request)) {
                $href .= strpos($href, '?') !== false
                    ? '&'
                    : '?';
                $href .= http_build_query($request);
            }
        }

        if (!empty($href) && $this->modx->getOption('xhtml_urls', null, false)) {
            $href = preg_replace("/&(?!amp;)/", "&amp;", $href);
        }

        $data = [
            'page' => $page,
            'pageNo' => $page,
            'href' => $href,
        ];
        if ($current !== null && $pages !== null) {
            $data = array_merge(
                $data,
                PageItemState::placeholders($page, $current, $pages, $skip)
            );
        }

        return !empty($tpl)
            ? $this->pdoTools->getChunk($tpl, $data)
            : $href;
    }

    /**
     * @param string $url
     * @param int $page Slot number
     * @param int $current Current page
     * @param int $pages Total pages
     * @param bool $skip
     * @return string
     */
    protected function renderPageItem($url, $page, $current, $pages, $skip = false)
    {
        $tpl = PageItemState::tpl($this->pdoTools->config(), $page, $current, $skip);
        if (empty($tpl)) {
            return '';
        }

        return $this->makePageLink($url, $page, $tpl, $current, $pages, $skip);
    }

    /**
     * Skip chunk without a page href (modern pagination separators).
     *
     * @param int $pages
     * @return string
     */
    protected function renderPageSkip($pages = 0)
    {
        $tpl = $this->pdoTools->config('tplPageSkip');
        if (empty($tpl)) {
            return '';
        }

        return $this->pdoTools->getChunk(
            $tpl,
            PageItemState::placeholders(0, 0, $pages, true)
        );
    }

    /**
     * Creates the correct URL of the page, including folder subdomains.
     * @see https://github.com/modx-pro/pdoTools/issues/318
     *
     * @param string $uri
     * @return string
     */
    public function getCanonicalUrl($uri = '')
    {
        $siteUrl = $this->modx->getOption('site_url');
        if ($this->modx->context->key !== 'web' && !empty(trim($this->modx->getOption('base_url'), ' /'))) {
            $siteUrl = strstr($siteUrl, $this->modx->getOption('base_url'), true) . '/';
        }
        return $siteUrl . ltrim($uri, '/');
    }

    /**
     * Classic pagination: 3,4,5,6,7,8,9,10,11,12,13,14
     *
     * @param int $page
     * @param int $pages
     * @param string $url
     *
     * @return string
     */
    public function buildClassicPagination($page = 1, $pages = 5, $url = '')
    {
        $pageLimit = $this->pdoTools->config('pageLimit');

        if ($pageLimit > $pages) {
            $pageLimit = 0;
        } else {
            // -1 because we need to show current page
            $tmp = (int)floor(($pageLimit - 1) / 2);
            $left = $tmp;                        // Pages from left
            $right = $pageLimit - $left - 1;    // Pages from right

            if ($page - 1 === 0) {
                $right += $left;
                $left = 0;
            } elseif ($page - 1 < $left) {
                $tmp = $left - ($page - 1);
                $left -= $tmp;
                $right += $tmp;
            } elseif ($pages - $page === 0) {
                $left += $right;
                $right = 0;
            } elseif ($pages - $page < $right) {
                $tmp = $right - ($pages - $page);
                $right -= $tmp;
                $left += $tmp;
            }

            $i = $page - $left;
            $pageLimit = $page + $right;
        }

        if (empty($i)) {
            $i = 1;
        }
        $pagination = '';
        while ($i <= $pages) {
            if (!empty($pageLimit) && $i > $pageLimit) {
                break;
            }

            $pagination .= $this->renderPageItem($url, $i, $page, $pages);
            $i++;
        }

        return $pagination;
    }


    /**
     * Modern pagination: 1,2,..,8,9,...,13,14
     *
     * @param int $page
     * @param int $pages
     * @param string $url
     *
     * @return string
     */
    public function buildModernPagination($page = 1, $pages = 5, $url = '')
    {
        $pageLimit = $this->pdoTools->config('pageLimit');

        if ($pageLimit >= $pages || $pageLimit < 7) {
            return $this->buildClassicPagination($page, $pages, $url);
        } else {
            $tmp = (int)floor($pageLimit / 3);
            $left = $right = $tmp;
            $center = $pageLimit - ($tmp * 2);
        }

        $pagination = [];
        // Left
        for ($i = 1; $i <= $left; $i++) {
            $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages);
        }

        // Right
        for ($i = $pages - $right + 1; $i <= $pages; $i++) {
            $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages);
        }

        // Center
        if ($page <= $left) {
            $i = $left + 1;
            while ($i <= $center + $left) {
                $skip = $i === $center + $left && !empty($this->pdoTools->config('tplPageSkip'));
                $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages, $skip);
                $i++;
            }
        } elseif ($page > $pages - $right) {
            $i = $pages - $right - $center + 1;
            while ($i <= $pages - $right) {
                $skip = $i === $pages - $right - $center + 1 && !empty($this->pdoTools->config('tplPageSkip'));
                $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages, $skip);
                $i++;
            }
        } else {
            if ($page - $center < $left) {
                $i = $left + 1;
                while ($i <= $center + $left) {
                    $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages);
                    $i++;
                }
                if (!empty($this->pdoTools->config('tplPageSkip'))) {
                    $key = ($page + 1 === $left + $center)
                        ? $pages - $right + 1
                        : $left + $center;
                    $pagination[$key] = $this->renderPageSkip($pages);
                }
            } elseif ($page + $center - 1 > $pages - $right) {
                $i = $pages - $right - $center + 1;
                while ($i <= $pages - $right) {
                    $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages);
                    $i++;
                }
                if (!empty($this->pdoTools->config('tplPageSkip'))) {
                    $key = ($page - 1 === $pages - $right - $center + 1)
                        ? $left
                        : $pages - $right - $center + 1;
                    $pagination[$key] = $this->renderPageSkip($pages);
                }
            } else {
                $tmp = (int)floor(($center - 1) / 2);
                $i = $page - $tmp;
                while ($i < $page - $tmp + $center) {
                    $pagination[$i] = $this->renderPageItem($url, $i, $page, $pages);
                    $i++;
                }
                if (!empty($this->pdoTools->config('tplPageSkip'))) {
                    $skip = $this->renderPageSkip($pages);
                    $pagination[$left] = $pagination[$pages - $right + 1] = $skip;
                }
            }
        }

        ksort($pagination);

        return implode($pagination);
    }

}
