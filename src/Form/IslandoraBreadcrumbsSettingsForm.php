<?php

namespace Drupal\islandora_breadcrumbs\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure islandora_breadcrumbs settings.
 */
class IslandoraBreadcrumbsSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'islandora_breadcrumbs.breadcrumbs';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_breadcrumbs_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config(static::SETTINGS);

    $form['includeSelf'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include the current node in the breadcrumbs?'),
      '#default_value' => $config->get('includeSelf'),
    ];

    // Using the textarea instead of a select so the site maintainer can
    // provide an ordered list of items rather than simply selecting from a
    // list which enforces it's own order.
    $form['referenceFields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Entity Reference fields to follow'),
      '#default_value' => implode("\n", $config->get('referenceFields')),
      '#description' => $this->t("Entity Reference field machine names to follow when building the breadcrumbs.<br>One per line.<br>Valid options: @options",
        [
          "@options" => implode(", ", static::getNodeEntityReferenceFields()),
        ]
      ),
      '#element_validate' => [[get_class($this), 'validateReferenceFields']],

    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns a list of node entity reference field machine names.
   *
   * We use this for building the form field description and for
   * validating the reference fields value.
   */
  protected static function getNodeEntityReferenceFields() {
    return array_keys(\Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference')['node']);
  }

  /**
   * Turns a text area into an array of values.
   *
   * Used for validating the field reference text area
   * and saving the form state.
   */
  protected static function textToArray($string) {
    return array_filter(array_map('trim', explode("\n", $string)), 'strlen');
  }

  /**
   * Callback for settings form.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   generic form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form for the form this element belongs to.
   *
   * @see \Drupal\Core\Render\Element\FormElement::processPattern()
   */
  public static function validateReferenceFields(array $element, FormStateInterface $form_state) {

    $valid_fields = static::getNodeEntityReferenceFields();

    foreach (static::textToArray($element['#value']) as $value) {
      if (!in_array($value, $valid_fields)) {
        $form_state->setError($element, t('"@field" is not a valid entity reference field!', ["@field" => $value]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('referenceFields', static::textToArray($form_state->getValue('referenceFields')))
      ->set('includeSelf', $form_state->getValue('includeSelf'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
