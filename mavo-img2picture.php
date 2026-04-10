<?php
/**
 * Plugin Name: Mavo Img2Picture
 * Description: Converts img tags in posts to responsive picture tags with WebP support, on the fly.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      Mavo
 * License:     GPL v2 or later
 */

defined('ABSPATH') || exit;

class Mavo_Img2Picture {

    public function __construct() {
        // Priority 9: run before Jetpack Photon (priority 10), which rewrites
        // image URLs to its CDN (i0.wp.com) and would break our URL derivation.
        add_filter('the_content',          [$this, 'transform'], 9);
        add_filter('post_thumbnail_html',  [$this, 'transform'], 9);
        add_filter('wp_get_attachment_image', [$this, 'transform'], 9);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function enqueue_styles(): void {
        wp_enqueue_style(
            'mavo-img2picture',
            plugin_dir_url(__FILE__) . 'mavo-img2picture.css',
            [],
            '1.0.0'
        );
    }

    public function transform(string $content): string {
        if (stripos($content, '<img') === false) {
            return $content;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<html><head><meta charset="UTF-8"></head><body><div id="mavo-root">'
            . $content
            . '</div></body></html>'
        );
        libxml_clear_errors();

        $root = $dom->getElementById('mavo-root');
        if (!$root) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $imgs  = iterator_to_array($xpath->query('.//img', $root));

        // Reverse order so replacements don't invalidate remaining node references
        foreach (array_reverse($imgs) as $img) {
            if ($img instanceof DOMElement) {
                $this->process_img($dom, $img);
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output;
    }

    private function process_img(DOMDocument $dom, DOMElement $img): void {
        $width  = (int) $img->getAttribute('width');
        $height = (int) $img->getAttribute('height');
        $src    = trim($img->getAttribute('src'));

        // Only convert full-width images (960px or wider)
        if ($width < 960 || empty($src)) {
            return;
        }

        // Skip CDN-rewritten URLs (e.g. Jetpack Photon i0.wp.com or query-string variants).
        // These won't have the expected file-system layout for 640/480 webp siblings.
        if (str_contains($src, 'i0.wp.com') || str_contains($src, '?')) {
            return;
        }

        // --- Derive variant URLs ---
        $info  = pathinfo($src);
        $base  = $info['dirname'] . '/' . $info['filename'];
        $ext   = $info['extension'] ?? '';
        $ratio = $height > 0 ? $height / $width : 0;
        $h640  = $ratio > 0 ? (int) round(640 * $ratio) : 0;
        $h480  = $ratio > 0 ? (int) round(480 * $ratio) : 0;

        $src_960 = $src;
        $src_640 = $h640 ? "{$base}-640x{$h640}.{$ext}" : '';
        $src_480 = $h480 ? "{$base}-480x{$h480}.{$ext}" : '';

        // srcset strings: full-size first, then smaller variants
        $srcset_webp = "{$src_960}.webp {$width}w";
        $srcset_jpg  = "{$src_960} {$width}w";
        if ($src_640) {
            $srcset_webp .= ", {$src_640}.webp 640w";
            $srcset_jpg  .= ", {$src_640} 640w";
        }
        if ($src_480) {
            $srcset_webp .= ", {$src_480}.webp 480w";
            $srcset_jpg  .= ", {$src_480} 480w";
        }

        // Content column is max 960px; below that, image fills the viewport
        $sizes = '(max-width: 960px) 100vw, 960px';

        // --- Build <picture> ---
        $picture = $dom->createElement('picture');

        // Propagate layout alignment class (aligncenter etc.) to <picture> itself
        // so theme CSS keeps working at the container level
        $orig_class = $img->getAttribute('class');
        foreach (['aligncenter', 'alignleft', 'alignright', 'alignnone'] as $align) {
            if (str_contains($orig_class, $align)) {
                $picture->setAttribute('class', $align);
                break;
            }
        }

        $s_webp = $dom->createElement('source');
        $s_webp->setAttribute('type', 'image/webp');
        $s_webp->setAttribute('srcset', $srcset_webp);
        $s_webp->setAttribute('sizes', $sizes);
        $picture->appendChild($s_webp);

        $s_jpg = $dom->createElement('source');
        $s_jpg->setAttribute('srcset', $srcset_jpg);
        $s_jpg->setAttribute('sizes', $sizes);
        $picture->appendChild($s_jpg);

        // Inner <img>: carries all original attributes + loading=lazy + responsive srcset
        $new_img = $dom->createElement('img');
        $new_img->setAttribute('src', $src_960);
        $new_img->setAttribute('srcset', $srcset_jpg);
        $new_img->setAttribute('sizes', $sizes);
        foreach (['alt', 'class', 'width', 'height'] as $attr) {
            $val = $img->getAttribute($attr);
            if ($val !== '') {
                $new_img->setAttribute($attr, $val);
            }
        }
        $new_img->setAttribute('loading', 'lazy');
        $picture->appendChild($new_img);

        // --- Detect context ---
        $parent        = $img->parentNode;
        $is_centered_p = $this->is_centered_p($parent);

        // Find an <em> caption: look inside the p first, then after it
        $em_node = $is_centered_p
            ? ($this->next_em($img) ?? $this->next_em($parent))
            : $this->next_em($img);

        // --- Wrap in <figure> if caption found ---
        if ($em_node) {
            $figure = $dom->createElement('figure');
            $figure->setAttribute('class', 'wp-picture-figure');
            $figcaption = $dom->createElement('figcaption');
            $figcaption->appendChild($dom->createTextNode(trim($em_node->textContent)));
            $figure->appendChild($picture);
            $figure->appendChild($figcaption);
            $replacement = $figure;
        } else {
            $replacement = $picture;
        }

        // --- Splice into DOM ---
        if ($is_centered_p) {
            $grandparent = $parent->parentNode;
            // If the em is outside the p, clean it up before removing the p
            if ($em_node && $em_node->parentNode !== $parent) {
                $this->remove_between($parent, $em_node);
                $em_node->parentNode?->removeChild($em_node);
            }
            $grandparent->replaceChild($replacement, $parent);
        } else {
            if ($em_node) {
                $this->remove_between($img, $em_node);
                $em_node->parentNode?->removeChild($em_node);
            }
            $parent->replaceChild($replacement, $img);
        }
    }

    /**
     * Return the next <em> sibling after $node, skipping over whitespace-only text nodes.
     * Returns null if anything other than whitespace or <em> is encountered.
     */
    private function next_em(DOMNode $node): ?DOMElement {
        $cur = $node->nextSibling;
        while ($cur) {
            if ($cur->nodeType === XML_TEXT_NODE) {
                if (trim($cur->nodeValue) !== '') {
                    return null; // Non-whitespace text blocks detection
                }
                $cur = $cur->nextSibling;
                continue;
            }
            if ($cur->nodeType === XML_ELEMENT_NODE && $cur->nodeName === 'em') {
                /** @var DOMElement $cur */
                return $cur;
            }
            return null; // Any other element blocks detection
        }
        return null;
    }

    /**
     * Remove all sibling nodes strictly between $start and $end (both exclusive).
     */
    private function remove_between(DOMNode $start, DOMNode $end): void {
        $node = $start->nextSibling;
        while ($node && $node !== $end) {
            $next = $node->nextSibling;
            $node->parentNode?->removeChild($node);
            $node = $next;
        }
    }

    /**
     * Return true if $node is a <p> with a text-align:center inline style.
     */
    private function is_centered_p(?DOMNode $node): bool {
        if (!$node instanceof DOMElement || $node->nodeName !== 'p') {
            return false;
        }
        return (bool) preg_match('/text-align\s*:\s*center/i', $node->getAttribute('style'));
    }
}

new Mavo_Img2Picture();
