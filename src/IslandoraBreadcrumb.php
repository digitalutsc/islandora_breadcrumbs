<?php

namespace Drupal\islandora_breadcrumbs;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Link;

/**
 * Class for Islandora Breadcrumb.
 */
class IslandoraBreadcrumb extends Breadcrumb {

  /**
   * Add Sub-link.
   */
  public function addSublink(Link $link) {
    $this->links[array_key_last($this->links)][] = $link;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addLink(Link $link) {
    $this->links[][] = $link;
    return $this;
  }

  /**
   * Add Link Set.
   */
  public function addLinkSet() {
    $this->links[] = [];
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $build = [
      '#cache' => [
        'contexts' => $this->cacheContexts,
        'tags' => $this->cacheTags,
        'max-age' => $this->cacheMaxAge,
      ],
    ];
    if (!empty($this->links)) {
      $build += [
        '#theme' => 'islandora_breadcrumb',
        '#links' => $this->links,
      ];
    }
    return $build;
  }

}
