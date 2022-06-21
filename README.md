# Islandora Lite Breadcrumbs
## Description
This module provides breadcrumbs for Islandora Lite content. 

## Behaviour
The path to the current object will be represented by all specified related content. For example, if the object is in multiple collections, all these collections will be represented in the breadcrumb. If there are multiple relationships between the homepage and the current object, these additional relationships will be represented by an ellipses.

<img width="569" alt="image" src="https://user-images.githubusercontent.com/63805048/174103870-041f9d2c-b2fc-44aa-a846-3057c3842db1.png">

Furthermore, links will go to the collection view if the related object is a collection. Other models for related objects will link to the node as normal.

Breadcrumbs for search results will display as "Home > Search Results". Location-based breadcrumbs for taxonomy term views are not currently supported.

## Theme Customization
Since this module replaces Drupal's native breadcrumbs, it will not make use of a theme's breadcrumb template. Instead, you must copy `islandora-breadcrumb.html.twig` into your theme's template folder and customize as needed.
