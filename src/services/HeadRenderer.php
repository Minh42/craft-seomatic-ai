<?php

namespace seomatic\craftai\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use seomatic\craftai\Plugin;
use yii\base\Component;

/**
 * Applies the plugin's stored meta overrides and JSON-LD to a rendered
 * front-end page (View::EVENT_AFTER_RENDER_PAGE_TEMPLATE). Output without
 * a `</head>` is returned unchanged, as is every page without stored data.
 */
class HeadRenderer extends Component
{
    public function apply(string $output): string
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest() || $request->getIsActionRequest()) {
            return $output;
        }
        $response = Craft::$app->getResponse();
        // Craft 4+ renders page templates through the 'template' response
        // format; plain controllers use 'html'.
        if (!in_array($response->format, [\yii\web\Response::FORMAT_HTML, 'template'], true)) {
            return $output;
        }
        $contentType = (string)$response->getHeaders()->get('Content-Type', '');
        if ($contentType !== '' && stripos($contentType, 'html') === false) {
            return $output;
        }
        $headEnd = stripos($output, '</head>');
        if ($headEnd === false) {
            return $output;
        }

        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        $entry = $element instanceof Entry && !empty($element->sectionId) ? $element : null;

        $plugin = Plugin::$plugin;
        $siteItems = $plugin->schema->getCached(null, $siteId);
        $entryItems = $entry ? $plugin->schema->getCached((int)$entry->id, $siteId) : [];
        $meta = null;
        if ($entry) {
            $meta = $plugin->meta->getCached((int)$entry->id, $siteId);
            // An entry with an SEOmatic field gets its meta written into the
            // field; only keys that field does not render are applied here.
            $seoField = $meta !== null ? $plugin->seomaticBridge->findField($entry) : null;
            if ($seoField !== null) {
                foreach ($plugin->seomaticBridge->nativeKeys($seoField) as $k) {
                    unset($meta[$k]);
                }
                if (empty($meta['title']) && empty($meta['description']) && empty($meta['canonical']) && empty($meta['noindex'])) {
                    $meta = null;
                }
            }
        }
        if ($meta === null && $siteItems === [] && $entryItems === []) {
            return $output;
        }

        $head = substr($output, 0, $headEnd);
        $rest = substr($output, $headEnd);
        if ($meta !== null) {
            $head = $this->applyMeta($head, $meta);
        }
        $scripts = '';
        foreach (array_merge($siteItems, $entryItems) as $item) {
            $json = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            if ($json !== false) {
                $scripts .= '<script type="application/ld+json">' . $json . "</script>\n";
            }
        }
        return $head . $scripts . $rest;
    }

    /** Replaces/inserts title, description, canonical and robots in the head. */
    public function applyMeta(string $head, array $meta): string
    {
        $insert = '';

        if (!empty($meta['title'])) {
            $tag = '<title>' . Html::encode($meta['title']) . '</title>';
            // Replace the first <title>, drop any further ones (a template
            // title plus nystudio107 SEOmatic's own, for example).
            [$head, $found] = $this->replaceTags($head, '#<title\b[^>]*>.*?</title>#is', $tag);
            if (!$found) {
                $insert .= $tag . "\n";
            }
        }

        if (!empty($meta['description'])) {
            $tag = '<meta name="description" content="' . Html::encode($meta['description']) . '">';
            [$head, $found] = $this->replaceTags($head, '#<meta\b[^>]*\bname\s*=\s*["\']?description["\']?[^>]*>#i', $tag);
            if (!$found) {
                $insert .= $tag . "\n";
            }
        }

        if (!empty($meta['canonical'])) {
            $tag = '<link rel="canonical" href="' . Html::encode($meta['canonical']) . '">';
            [$head, $found] = $this->replaceTags($head, '#<link\b[^>]*\brel\s*=\s*["\']?canonical["\']?[^>]*>#i', $tag);
            if (!$found) {
                $insert .= $tag . "\n";
            }
        }

        if (!empty($meta['noindex'])) {
            $pattern = '#<meta\b[^>]*\bname\s*=\s*["\']?robots["\']?[^>]*>#i';
            $content = 'noindex';
            if (preg_match($pattern, $head, $m) && preg_match('#\bcontent\s*=\s*["\']([^"\']*)["\']#i', $m[0], $c)) {
                $tokens = array_filter(array_map('trim', explode(',', strtolower($c[1]))));
                $keep = array_diff($tokens, ['index', 'all', 'noindex', 'none']);
                if (in_array('none', $tokens, true)) {
                    $keep[] = 'nofollow';
                }
                $content = implode(', ', array_merge(['noindex'], array_values(array_unique($keep))));
            }
            $tag = '<meta name="robots" content="' . Html::encode($content) . '">';
            [$head, $found] = $this->replaceTags($head, $pattern, $tag);
            if (!$found) {
                $insert .= $tag . "\n";
            }
        }

        return $head . $insert;
    }

    /**
     * Replaces the first match with $tag and removes any further matches.
     * @return array{0:string,1:bool}
     */
    private function replaceTags(string $head, string $pattern, string $tag): array
    {
        $n = 0;
        $result = preg_replace_callback($pattern, function () use ($tag, &$n) {
            $n++;
            return $n === 1 ? $tag : '';
        }, $head);
        return [$result ?? $head, $n > 0];
    }
}
