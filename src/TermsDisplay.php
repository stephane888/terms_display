<?php

namespace Drupal\terms_display;

class TermsDisplay {
  
  public static function getThemeHooks() {
    $hooks = [];
    $hooks['terms_display'] = [
      'render element' => 'element',
      'variables' => [
        'tid' => '',
        'parents' => null,
        'items' => [],
        'subitem' => [],
        'route_tid' => null,
        'max_depth' => null,
        'collapsible' => null
      ],
      'preprocess functions' => [
        'template_preprocess_terms_display'
      ],
      'file' => 'terms_display.theme.inc'
    ];
    return $hooks;
  }
  
}