<?php

namespace Drupal\terms_display\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for terms display routes.
 */
class TermsDisplayController extends ControllerBase {
  
  /**
   * Builds the response.
   */
  public function build() {
    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t(" It works! ")
    ];
    return $build;
  }
  
}
