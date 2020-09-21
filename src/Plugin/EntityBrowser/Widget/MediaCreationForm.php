<?php

declare(strict_types = 1);

namespace Drupal\oe_media\Plugin\EntityBrowser\Widget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\entity_browser\WidgetBase;
use Drupal\entity_browser\WidgetValidationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Entity browser widget linking to the creation form for any media.
 *
 * @EntityBrowserWidget(
 *   id = "oe_media_creation_form",
 *   label = @Translation("Media creation form"),
 *   description = @Translation("Creation form for any media."),
 *   auto_select = FALSE
 * )
 */
class MediaCreationForm extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * MediaCreationForm constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\WidgetValidationManager $validation_manager
   *   The Widget Validation Manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager, $validation_manager);

    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $aditional_widget_parameters) {
    $form = parent::getForm($original_form, $form_state, $aditional_widget_parameters);

    $context = $form_state->get('entity_browser');
    $target_bundles = $context['widget_context']['target_bundles'] ?? [];
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('media');
    if ($target_bundles) {
      $bundles = array_intersect_key($bundles, $target_bundles);
    }

    $options = [];
    foreach ($bundles as $bundle => $info) {
      $options[$bundle] = $info['label'];
    }

    $id = Html::getId('media_entity_form');

    $form['media_bundle'] = [
      '#type' => 'select',
      '#title' => 'Bundle',
      '#options' => $options,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [$this, 'ajaxCallback'],
        'wrapper' => $id,
      ],
    ];

    $form['entity_form'] = [
      '#type' => 'container',
      '#id' => $id,
    ];

    // Here we need to use the user input because there may be other ajax
    // requests (such as the one for a file upload) which won't contain the
    // bundle in the form state values.
    $user_input = $form_state->getUserInput();
    $bundle = isset($user_input['media_bundle']) ? $user_input['media_bundle'] : NULL;
    if ($bundle && isset($options[$bundle])) {
      // Pretend to be IEFs submit button.
      $form['#submit'] = [
        [
          'Drupal\inline_entity_form\ElementSubmit',
          'trigger',
        ],
      ];
      $form['actions']['submit']['#ief_submit_trigger'] = TRUE;
      $form['actions']['submit']['#ief_submit_trigger_all'] = TRUE;

      $form['entity_form']['inline_entity_form'] = [
        '#type' => 'inline_entity_form',
        '#op' => 'add',
        '#entity_type' => 'media',
        '#bundle' => $bundle,
        '#form_mode' => 'default',
      ];
    }

    return $form;
  }

  /**
   * Ajax callback to generate the media entity form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An ajax response object.
   */
  public function ajaxCallback(array &$form) {
    $element = NestedArray::getValue($form, array_merge([
      $form['#browser_parts']['widget'],
      'entity_form',
    ]));
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state) {
    $entity_form = $form[$form['#browser_parts']['widget']]['entity_form'];
    if (!isset($entity_form['inline_entity_form'])) {
      return [];
    }
    return [$entity_form['inline_entity_form']['#entity']];
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getTriggeringElement()['#eb_widget_main_submit'])) {
      $entities = $this->prepareEntities($form, $form_state);
      array_walk(
        $entities,
        function (EntityInterface $entity) {
          $entity->save();
        }
      );
      $this->selectEntities($entities, $form_state);
    }
  }

}
