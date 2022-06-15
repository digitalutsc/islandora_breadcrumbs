<?php

namespace Drupal\islandora_breadcrumbs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Utility\Unicode;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\islandora_breadcrumbs\IslandoraBreadcrumb;

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

  // check whether is type islandora object
  var $isIslandora;

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
      if($node->hasField($this->config->get('referenceField'))){
        global $isIslandora;
        $isIslandora = true;
      }
      return (!empty($node));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    //initialize breadcrumb and create link to Home
    $breadcrumb = new IslandoraBreadcrumb();
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    $parameters = $route_match->getParameters()->all();
    if (isset($parameters['taxonomy_term'])) {
      // breadcrumb for taxonomy term
      $term = $parameters['taxonomy_term'];
      $breadcrumb->addLink(Link::createFromRoute($term->getName(), '<none>'));

      /*
      $bundle_machine_name =  $term->bundle();
      $breadcrumb->addLink(Link::createFromRoute($bundle_machine_name, '<none>'));
      */

    }else if(isset($parameters['view_id'])){
      $path = \Drupal::service('path.current')->getPath();
      $url_object = \Drupal::service('path.validator')->getUrlIfValid($path);
      $route_name = $url_object->getRouteName();
      $title = '';
      $path_elements = explode('/', $path);
      $nid = "";
      foreach($path_elements as $pe) {

        if (intval($pe)) {
          // if it's node id
          $node = \Drupal\node\Entity\Node::load($pe);
          if($node->hasField($this->config->get('referenceField'))){
            $nid = $pe;
            // if islandora object
            $title = $node->getTitle();
          }
        }
      }
      //$title = str_replace(['-', '_'], ' ', Unicode::ucwords(end($path_elements)));
      if ($parameters['view_id']  === "advanced_search") {
        $breadcrumb->addLink(Link::createFromRoute("Search Results", '<none>'));
      }else {
        $breadcrumb->addLink(Link::createFromRoute($title, $route_name, ['node' => $nid]));
      }
    }else{
      global $isIslandora;
      if($isIslandora){
        // breadcrumb for islandora object
        $nid = $route_match->getRawParameters()->get('node');
        $node = $this->nodeStorage->load($nid);

        $chain = [];
        $this->walkMembership($node, $chain);

        if (count($chain) < 2) {
          $this->walkPartOf($node, $chain);
        }

        if (!$this->config->get('includeSelf')) {
          array_pop($chain);
        }
        $breadcrumb->addCacheableDependency($node);

        // Add membership chain to the breadcrumb.
        foreach ($chain as $chainlink) {
          $link = $chainlink->toLink()->toString()->getGeneratedLink();

          // extract node from the link
          preg_match_all('/<a[^>]+href=([\'"])(?<href>.+?)\1[^>]*>/i', $link, $result);
          if (!empty($result)) {
            # Found a link.
            $node_url = $result['href'][0];

            $node_matched = preg_match('/node\/(\d+)/', $node_url, $matches);
            if ($node_matched === 0) {
              // add to handle node id with alias (ark url)
              $path = \Drupal::service('path_alias.manager')->getPathByAlias(urldecode($node_url));
              $node_matched = preg_match('/node\/(\d+)/', $path, $matches);
            }

            if($node_matched) {
              $nid = $matches[1];
              $node = Node::load($nid);

              if (Term::load($node->get('field_model')->target_id)->get('name')->value ==="Collection" ){
                  $url_object = \Drupal::service('path.validator')->getUrlIfValid("/collection/%node");
                  $route_name = $url_object->getRouteName();
                  // if the parent is collection, replace the node link with collection view link
                  $breadcrumb->addLink(Link::createFromRoute($node->getTitle(), $route_name, ['node' => $nid]));
              }
              else if (Term::load($node->get('field_model')->target_id)->get('name')->value ==="Paged Content" ){
                $breadcrumb->addLink(Link::createFromRoute($node->getTitle(), "entity.node.canonical", ['node' => $node->id()]));
              }
              else {

              }
            }
          }
          else {
            $breadcrumb->addCacheableDependency($chainlink);
            $breadcrumb->addLink($chainlink->toLink());
          }
        }

      }else{
        // default breadcrumb
        $parameters = $route_match->getParameters()->all();
        $node = $parameters['node'];
        $vid = \Drupal::entityTypeManager()->getStorage('node')->getLatestRevisionId($node->id());
        $node_new = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
        $node_array = $node_new->toArray();

      }

      // add current page title to the breadcrumb.
      if ($breadcrumb && !\Drupal::service('router.admin_context')->isAdminRoute() && !\Drupal::service('path.matcher')->isFrontPage()) {
        $title = \Drupal::service('title_resolver')->getTitle(\Drupal::request(), $route_match->getRouteObject());
        if (!empty($title)) {
          $breadcrumb->addLink(\Drupal\Core\Link::createFromRoute($title, '<none>'));
        }
      }
    }
    $breadcrumb->addCacheContexts(['route']);
    return $breadcrumb;
  }

  public static function getNodeIdByAlias(string $alias) {
    $data = NULL;
    try {
      $query = \Drupal::entityQuery('path_alias');
      $query->condition('alias', '/' . $alias, '=');
      $aliasIds = $query->execute();
      foreach ($aliasIds as $id) {
        $path = \Drupal::entityTypeManager()->getStorage('path_alias')->load($id)->getPath();
        $data = (int) str_replace("/node/", "", $path);
      }
    } catch (\Exception $e) {
      $data = $e->getMessage();
    }
    return $data;
  }
  /**
   * Follows chain of field_member_of links.
   *
   * We pass crumbs by reference to enable checking for looped chains.
   */
  protected function walkMembership(EntityInterface $entity, &$crumbs) {
    // Avoid infinate loops, return if we've seen this before.
    foreach ($crumbs as $crumb) {
      if ($crumb->uuid == $entity->uuid) {
        return;
      }
    }

    // Add this item onto the pile.
    array_unshift($crumbs, $entity);

    if ($this->config->get('maxDepth') > 0 && count($crumbs) >= $this->config->get('maxDepth')) {
      return;
    }

    // Find the next in the chain, if there are any.
    if ($entity->hasField($this->config->get('referenceField')) &&
      !$entity->get($this->config->get('referenceField'))->isEmpty() &&
      $entity->get($this->config->get('referenceField'))->entity instanceof EntityInterface) {
      $this->walkMembership($entity->get($this->config->get('referenceField'))->entity, $crumbs);
    }
  }

  /**
   * Follows chain of field_member_of links.
   *
   * We pass crumbs by reference to enable checking for looped chains.
   */
  protected function walkPartOf(EntityInterface $entity, &$crumbs) {

    // Find the next in the chain, if there are any.
    if ($entity->hasField("field_part_of") &&
      count($entity->get("field_part_of")->referencedEntities()) >0 &&
      $entity->get("field_part_of")->referencedEntities()[0] instanceof EntityInterface) {

      $first_page = $entity->get("field_part_of")->referencedEntities()[0];
      // Add this item onto the pile.
      $this->walkMembership($first_page, $crumbs);

      // Add this item onto the pile.
      array_unshift($crumbs, $entity);
    }
  }

}
