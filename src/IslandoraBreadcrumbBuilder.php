<?php

namespace Drupal\islandora_breadcrumbs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;

/**
 * Provides breadcrumbs for nodes using a configured entity reference field.
 */
class IslandoraBreadcrumbBuilder implements BreadcrumbBuilderInterface {
  use StringTranslationTrait;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Storage to load nodes.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Check whether is type islandora object.
   *
   * @var bool
   */
  public $isIslandora;

  /**
   * Constructs a breadcrumb builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   Storage to load nodes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, ConfigFactoryInterface $config_factory) {
    $this->nodeStorage = $entity_manager->getStorage('node');
    $this->config = $config_factory->get('islandora_breadcrumbs.breadcrumbs');
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $attributes) {
    // Using getRawParameters for consistency (always gives a
    // node ID string) because getParameters sometimes returns
    // a node ID string and sometimes returns a node object.
    if (\Drupal::service('path.matcher')->isFrontPage()) {
      return FALSE;
    }
    $parameters = $attributes->getParameters()->all();
    if (isset($parameters['taxonomy_term'])) {
      return TRUE;
    }
    if (isset($parameters['view_id'])) {
      return TRUE;
    }
    $nid = $attributes->getRawParameters()->get('node');
    if (!empty($nid)) {
      $node = $this->nodeStorage->load($nid);
      if (!is_null($node) && $this->nodeHasReferenceFields($node)) {
        global $_islandora_breadcrumbs_isIslandora;
        $_islandora_breadcrumbs_isIslandora = TRUE;
      }
      return (!empty($node));
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    // Initialize breadcrumb and create link to Home.
    $breadcrumb = new IslandoraBreadcrumb();
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    $parameters = $route_match->getParameters()->all();
    if (isset($parameters['taxonomy_term'])) {
      // Breadcrumb for taxonomy term.
      $term = $parameters['taxonomy_term'];
      $breadcrumb->addLink(Link::createFromRoute($term->getName(), '<none>'));

      /*
      $bundle_machine_name =  $term->bundle();
      $breadcrumb->addLink(
      Link::createFromRoute($bundle_machine_name, '<none>')
      );
       */

    }
    elseif (isset($parameters['view_id'])) {
      $path = \Drupal::service('path.current')->getPath();
      $url_object = \Drupal::service('path.validator')->getUrlIfValid($path);
      $route_name = $url_object->getRouteName();
      $title = '';
      $path_elements = explode('/', $path);
      $nid = "";
      $node = NULL;
      foreach ($path_elements as $pe) {

        if (intval($pe)) {
          // If it's node id.
          $node = $this->getTranslatedNode(Node::load($pe));
          if (!is_null($node) && $this->nodeHasReferenceFields($node)) {
            $nid = $pe;
            // If islandora object.
            $title = $node->getTitle();
          }
        }
      }

      //$title = str_replace(['-', '_'], ' ', Unicode::ucwords(end($path_elements)));
      $view = \Drupal\views\Views::getView($parameters['view_id']);
      $view_title = $view->getTitle();
      if (is_null($node)){
        $breadcrumb->addLink(Link::createFromRoute($view_title, '<none>'));
      } else {
        $this->setReferenceBreadcrumbs($breadcrumb, $node);
        $breadcrumb->addLink(Link::createFromRoute($title, $route_name, $parameters));
      }
    }
    else {
      global $_islandora_breadcrumbs_isIslandora;
      if ($_islandora_breadcrumbs_isIslandora) {
        // Breadcrumb for islandora object.
        $nid = $route_match->getRawParameters()->get('node');
        $node = $this->nodeStorage->load($nid);
        $this->setReferenceBreadcrumbs($breadcrumb, $node);
      }

      // Add current page title to the breadcrumb.
      if ($this->config->get('includeSelf') && $breadcrumb && !\Drupal::service('router.admin_context')->isAdminRoute() && !\Drupal::service('path.matcher')->isFrontPage()) {
        $title = \Drupal::service('title_resolver')->getTitle(\Drupal::request(), $route_match->getRouteObject());
        if (!empty($title)) {
          $breadcrumb->addLink(Link::createFromRoute($title, '<none>'));
        }
      }
    }
    $breadcrumb->addCacheContexts(['route']);
    return $breadcrumb;
  }

  /**
   * Sets trail of breadcrumbs between home and current page.
   *
   * @param \Drupal\islandora_breadcrumbs\IslandoraBreadcrumb $breadcrumb
   *   Breadcrumb to set.
   * @param \Drupal\node\Entity\Node $node
   *   Node to get breadcrumb of.
   */
  protected function setReferenceBreadcrumbs(IslandoraBreadcrumb &$breadcrumb, Node $node = NULL) {
    if ($node == NULL) {
      return;
    }
    $breadcrumb->addCacheableDependency($node);

    // Get entities from referenced fields.
    $referenced_entities = $this->getReferencedEntities($node);

    // Check referenced fields for members.
    foreach ($referenced_entities as $referenced_entity) {
      $link = $referenced_entity->toLink()->toString()->getGeneratedLink();
      $node = $this->extractNode($referenced_entity);
      $refs = $this->getReferencedEntities($node);
      if (count($refs) > 0) {
        $breadcrumb->addLink(Link::createFromRoute($this->t('...'), '<none>'));
        break;
      }
    }

    // Add members to breadcrumb.
    if (count($referenced_entities) > 0) {
      $breadcrumb->addLinkSet();
    }
    foreach ($referenced_entities as $referenced_entity) {
      $link = $referenced_entity->toLink()->toString()->getGeneratedLink();
      $node = $this->extractNode($referenced_entity);

      if ($node != NULL) {
        $breadcrumb->addCacheableDependency($node);
        $breadcrumb->addSublink($this->getViewLink($node));
      }
      else {
        $breadcrumb->addCacheableDependency($referenced_entity);
        $breadcrumb->addSublink($referenced_entity->toLink());
      }
    }
  }

  /**
   * Gets referenced fields (set from the config) of node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Node to get referenced fields from.
   *
   * @return array
   *   List of objects referenced by $node.
   */
  protected function getReferencedEntities(Node $node = NULL) {
    $referenced_entities = [];
    if ($node == NULL) {
      return $referenced_entities;
    }
    foreach ($this->config->get('referenceFields') as $reference_field) {
      if ($node->hasField($reference_field) &&
        !$node->get($reference_field)->isEmpty() &&
        $node->get($reference_field)->entity instanceof EntityInterface) {
        $entities = $node->get($reference_field)->referencedEntities();
        $referenced_entities = array_merge($referenced_entities, $entities);
      }
    }
    return $referenced_entities;
  }

  /**
   * Determine node has entity reference fields.
   */
  protected function nodeHasReferenceFields(Node $node) {
    foreach ($this->config->get('referenceFields') as $reference_field) {
      if ($node->hasField($reference_field)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets link from node if it has a special view.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Node to get link from.
   *
   * @return \Drupal\Core\Link
   *   Link representing node.
   */
  protected function getViewLink(Node $node) {
    $nid = $node->id();
    if (Term::load($node->get('field_model')->target_id)->get('name')->value === "Paged Content") {
      return Link::createFromRoute($node->getTitle(), "entity.node.canonical", ['node' => $node->id()]);
    }
    else {
      return $node->toLink();
    }
  }

  /**
   * Gets node translated to current language. If no, returns untranslated node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Node to be translated.
   *
   * @return \Drupal\node\Entity\Node
   *   Translated node.
   */
  protected function getTranslatedNode(Node $node = NULL) {
    if (is_null($node)) {
      return NULL;
    }
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    return $node;
  }

  /**
   * Extracts node from entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to get node from.
   *
   * @return \Drupal\node\Entity\Node
   *   Node from entity.
   */
  protected function extractNode(EntityInterface $entity) {
    $link = $entity->toLink()->toString()->getGeneratedLink();

    // Extract node from the link.
    preg_match_all('/<a[^>]+href=([\'"])(?<href>.+?)\1[^>]*>/i', $link, $result);
    if (!empty($result)) {
      // Found a link.
      $node_url = $result['href'][0];

      $node_matched = preg_match('/node\/(\d+)/', $node_url, $matches);
      if ($node_matched === 0) {
        // Add to handle node id with alias (ark url)
        $path = \Drupal::service('path_alias.manager')->getPathByAlias(urldecode($node_url));
        $node_matched = preg_match('/node\/(\d+)/', $path, $matches);
      }

      if ($node_matched) {
        $nid = $matches[1];
        $node = Node::load($nid);
        return $this->getTranslatedNode($node);
      }
    }
    return NULL;
  }

}
