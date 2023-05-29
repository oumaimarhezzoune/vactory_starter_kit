<?php

namespace Drupal\vactory_dynamic_field\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\vactory_dynamic_field\ModalEnum;
use Drupal\vactory_dynamic_field\Plugin\Field\FieldWidget\FormWidgetTrait;
use Drupal\vactory_dynamic_field\WidgetsManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ModalForm class for vactory dynamic fields.
 */
class ModalForm extends FormBase {

  use FormWidgetTrait;

  /**
   * The plugin manager.
   *
   * @var \Drupal\vactory_dynamic_field\WidgetsManager
   */
  protected $widgetsManager;


  /**
   * The field being processed.
   *
   * @var string
   */
  protected $fieldId = NULL;

  /**
   * The widget being processed.
   *
   * @var string
   */
  protected $widget = NULL;

  /**
   * The widget being processed.
   *
   * @var array
   */
  protected $widgetData = [];

  /**
   * The widget being processed.
   *
   * @var int
   */
  protected $widgetRows = 1;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * Field wysiwyg dynamic cardinality.
   *
   * @var int
   */
  protected $cardinality;

  /**
   * Template ajax wrapper ID.
   *
   * @var string
   */
  protected $wrapperId;

  /**
   * Templates select modes.
   *
   * @var bool
   */
  protected $isDropdownSelectMode;

  /**
   * Text format fields.
   *
   * @var array
   */
  protected $textformatFields;

  /**
   * Extension path resolver service.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * Constructs a new ExampleConfigEntityExternalForm.
   *
   * @param \Drupal\vactory_dynamic_field\WidgetsManagerInterface $widgets_manager
   *   The widgets manager.
   * @param \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    WidgetsManagerInterface $widgets_manager,
    EntityFieldManager $entity_field_manager,
    ExtensionPathResolver $extensionPathResolver
  ) {
    $this->textformatFields = [];
    $this->widgetsManager = $widgets_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->extensionPathResolver = $extensionPathResolver;
    $this->isDropdownSelectMode = \Drupal::config('vactory_dynamic_field.settings')->get('is_dropdown_select_templates');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('vactory_dynamic_field.vactory_provider_manager'),
      $container->get('entity_field.manager'),
      $container->get('extension.path.resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynamic_field_modal_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $field_id = \Drupal::request()->query->get('field_id');
    $widget_id = \Drupal::request()->query->get('widget_id');
    $dialog_options = \Drupal::request()->request->get('dialogOptions');
    $widget_data = isset($dialog_options['data']) ? $dialog_options['data'] : NULL;
    $this->cardinality = \Drupal::request()->query->get('cardinality') ?: NULL;
    $this->wrapperId = \Drupal::request()->query->get('wrapper_id') ?: NULL;
    if (!$field_id || !is_string($field_id)) {
      throw new BadRequestHttpException('The "field_id" query parameter is required and must be a string.');
    }

    $this->fieldId = $field_id;

    // Look for a value in form state first.
    $this->widgetRows = $form_state->get('num_widgets');

    if ($widget_id && is_string($widget_id)) {
      $this->widget = $widget_id;
    }

    if ($widget_data && is_string($widget_data)) {
      $this->widgetData = json_decode($widget_data, TRUE);
      $widget_data = $this->widgetData;

      // This will get removed by usort.
      unset($this->widgetData['extra_field']);

      // Fallback for existing templates.
      // Which don't have _weight field yet.
      $widget_weight = 1;
      foreach ($this->widgetData as &$component) {
        if (!isset($component['_weight']) && is_array($component)) {
          $component['_weight'] = $widget_weight++;
        }
      }

      // Sort data.
      usort($this->widgetData, function ($item1, $item2) {
        return (int) ($item1['_weight'] <=> $item2['_weight']);
      });

      // Restore extra_field.
      if (isset($widget_data['extra_field'])) {
        $this->widgetData['extra_field'] = $widget_data['extra_field'];
      }

      if (array_key_exists('extra_field', $widget_data)) {
        unset($widget_data['extra_field']);
      }

      // Only count data widgets if we don't have a number yet!.
      if ($this->widgetRows === NULL) {
        $form_state->set('num_widgets', count($widget_data));
        $this->widgetRows = $form_state->get('num_widgets');
      }
    }
    else {
      if ($this->widgetRows === NULL) {
        // If no data.
        $form_state->set('num_widgets', 1);
        $this->widgetRows = $form_state->get('num_widgets');
      }
    }

    if (!$this->widget) {
      return $this->buildWidgetSelectorForm($form, $form_state);
    }
    else {
      return $this->buildWidgetForm($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWidgetForm(array &$form, FormStateInterface $form_state) {
    $settings = $this->widgetsManager->loadSettings($this->widget);

    $form['#prefix'] = '<div id="' . ModalEnum::FORM_WIDGET_AJAX_WRAPPER . '">';
    $form['#suffix'] = '</div>';

    $form['#tree'] = TRUE;
    $form['#parents'] = [];

    // The status messages that will contain any form errors.
    $form['status_messages'] = [
      '#type'   => 'status_messages',
      '#weight' => -10,
    ];

    // Form infos.
    if (isset($settings['infos'])) {
      $form['infos'] = [
        '#type'          => 'fieldset',
        '#title'         => $this->t('Infos'),
        '#title_display' => 'invisible',
        '#attributes'    => [
          'style' => 'background: aliceblue; margin-bottom: 10px;',
        ],
      ];

      foreach ($settings['infos'] as $id => $info) {
        if ($info['type'] === 'tokens_tree') {
          $form['infos'][$id] = [
            '#theme' => 'token_tree_link',
            '#show_restricted' => TRUE,
            '#weight' => 90,
          ];
        }
      }
    }

    $form['components'] = [
      '#type'  => 'fieldgroup',
      '#title' => $settings['name'],
    ];

    // Extra form elements.
    if (isset($settings['extra_fields'])) {
      $form['components']['extra_field'] = [
        '#type'          => 'fieldset',
        '#title'         => $settings['name'],
        '#title_display' => 'invisible',
        '#attributes'    => [
          'style' => 'background: whitesmoke; margin-bottom: 10px;',
        ],
      ];

      foreach ($settings['extra_fields'] as $field_id => $field) {
        if ($field_id == 'weight') {
          $form['components']['extra_field']['#weight'] = $field;
          continue;
        }
        if (strpos($field_id, 'group_') === 0) {
          // Wrapp fields in a collapsible fieldset.
          $form['components']['extra_field'][$field_id] = [
            '#type' => 'details',
            '#title' => $field['g_title'] ?? '',
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
          ];

          // Replace name property option token.
          if (isset($field['g_name'])) {
            if (preg_match('/\{(i|index)\}$/', $field['g_name'])) {
              $field['g_name'] = preg_replace('/\{(i|index)\}$/', '1', $field['g_name']);
            }
            $form['components']['extra_field'][$field_id]['#name'] = $field['g_name'];
          }

          // Handle conditional fields.
          if (isset($field['g_conditions'])) {
            $this->setVisibilityConditions($form['components']['extra_field'][$field_id], $field['g_conditions']);
            unset($field['g_conditions']);
          }

          foreach ($field as $field_key => $field_info) {
            $element_type = $field_info['type'] ?? NULL;
            $label = $field_info['label'] ?? '';
            $element_label = t('@field_label', ['@field_label' => $label]);
            $element_default_value = (isset($this->widgetData['extra_field'][$field_id][$field_key])) ? $this->widgetData['extra_field'][$field_id][$field_key] : NULL;
            $element_options = isset($field_info['options']) ? $field_info['options'] : [];

            $ds_field_name = '';
            if ($element_type == 'image' || $element_type == 'file') {
              // Save a copy of current parent.
              $form_parents = $form['#parents'] ?? [];
              $form['#parents'] = [
                'components',
                'extra_field',
                $field_id,
                $field_key,
              ];
              $ds_field_name = [
                str_replace(':', '_', $this->widget),
                $field_key,
                $field_id,
              ];
              $ds_field_name = implode('_', $ds_field_name);
              // Max 32 chars.
              $ds_field_name = 'm' . substr(md5($ds_field_name), 0, -1);
            }

            $form['components']['extra_field'][$field_id][$field_key] = $this->getFormElement($element_type, $element_label, $element_default_value, $element_options, $form, $form_state, $ds_field_name, $field_id, $field_key);

            // Handle conditional fields.
            if (isset($field_info['conditions'])) {
              $this->setVisibilityConditions($form['components']['extra_field'][$field_id][$field_key], $field_info['conditions']);
            }

            if ($element_type == 'text_format') {
              $this->textformatFields[] = ['components', 'extra_field', $field_id, $field_key];
            }

            if ($element_type == 'image' || $element_type == 'file') {
              // Restore parent for other fields.
              $form['#parents'] = $form_parents;
            }
          }
        }
        else {
          $element_type = $field['type'];
          $element_label = t('@field_label', ['@field_label' => $field['label']]);
          $element_default_value = (isset($this->widgetData['extra_field'][$field_id])) ? $this->widgetData['extra_field'][$field_id] : NULL;
          $element_options = isset($field['options']) ? $field['options'] : [];

          $ds_field_name = '';
          if ($element_type == 'image' || $element_type == 'file') {
            // Save a copy of current parent.
            $form_parents = $form['#parents'] ?? [];
            $form['#parents'] = ['components', 'extra_field', $field_id];
            $ds_field_name = [
              str_replace(':', '_', $this->widget),
              $field_id,
            ];
            $ds_field_name = implode('_', $ds_field_name);
            // Max 32 chars.
            $ds_field_name = 'm' . substr(md5($ds_field_name), 0, -1);
          }

          $form['components']['extra_field'][$field_id] = $this->getFormElement($element_type, $element_label, $element_default_value, $element_options, $form, $form_state, $ds_field_name, $field_id);

          // Handle conditional fields.
          if (isset($field['conditions'])) {
            $this->setVisibilityConditions($form['components']['extra_field'][$field_id], $field['conditions']);
          }

          if ($element_type == 'text_format') {
            $this->textformatFields[] = ['components', 'extra_field', $field_id];
          }

          if ($element_type == 'image' || $element_type == 'file') {
            // Restore parent for other fields.
            $form['#parents'] = $form_parents;
          }
        }
      }
    }

    $is_multiple = FALSE;
    $component_wrapper_type = 'fieldset';
    if (isset($settings['multiple']) && (bool) $settings['multiple'] === TRUE) {
      global $base_url;
      $drag_icon = $base_url . '/' . $this->extensionPathResolver->getPath('module', 'vactory_dynamic_field') . '/icons/icon-drag-move.svg';
      $icon_drag = '<img src="' . $drag_icon . '" class="df-components-sortable-handler"/>';
      $component_wrapper_type = 'details';
      $is_multiple = TRUE;
    }

    // Add component fields.
    for ($i = 0; $i < $this->widgetRows; $i++) {
      // Components wrapper.
      $form['components'][$i] = [
        '#type'          => $component_wrapper_type,
        '#title'         => $this->t('Component'),
        '#title_display' => 'invisible',
        '#attributes'    => [
          'style' => 'margin-bottom: 3px; position: relative;',
        ],
      ];

      if ($is_multiple) {
        // If multiple components case then add drag icon.
        $form['components'][$i]['#title'] = $this->t('Component') . ' ' . ($i + 1) . ' ' . $icon_drag;
        $form['components'][$i]['#open'] = TRUE;
        if ($i === 0) {
          $form['components'][$i]['#prefix'] = '<div id="sortable-components">';
        }
        if ($i === $this->widgetRows - 1) {
          $form['components'][$i]['#suffix'] = '</div>';
        }
      }

      // Form elements.
      foreach ($settings['fields'] as $field_id => $field) {
        if (strpos($field_id, 'group_') === 0) {
          // Wrapp fields in a collapsible fieldset.
          $form['components'][$i][$field_id] = [
            '#type' => 'details',
            '#title' => $field['g_title'],
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
          ];

          // Replace name property option token.
          if (isset($field['g_name'])) {
            if (preg_match('/\{(i|index)\}$/', $field['g_name'])) {
              $field['g_name'] = preg_replace('/\{(i|index)\}$/', $i, $field['g_name']);
            }
            $form['components'][$i][$field_id]['#name'] = $field['g_name'];
          }

          // Handle conditional fields.
          if (isset($field['g_conditions'])) {
            $this->setVisibilityConditions($form['components'][$i][$field_id], $field['g_conditions'], $i);
            unset($field['g_conditions']);
          }

          foreach ($field as $field_key => $field_info) {
            if ($field_key == 'g_title') {
              continue;
            }
            $element_type = $field_info['type'];
            $label = $field_info['label'] ?? '';
            $element_label = t('@field_label', ['@field_label' => $label]);

            $element_default_value = (isset($this->widgetData[$i][$field_id][$field_key])) ? $this->widgetData[$i][$field_id][$field_key] : NULL;
            $element_options = isset($field_info['options']) ? $field_info['options'] : [];

            $ds_field_name = '';
            if ($element_type == 'image' || $element_type == 'file') {
              // Save a copy of current parent.
              $form_parents = $form['#parents'] ?? [];
              $form['#parents'] = ['components', $i, $field_id, $field_key];
              $ds_field_name = [
                str_replace(':', '_', $this->widget),
                $field_key,
                $field_id,
                $i,
              ];
              $ds_field_name = implode('_', $ds_field_name);
              // Max 32 chars.
              $ds_field_name = 'f' . substr(md5($ds_field_name), 0, -1);
            }

            $form['components'][$i][$field_id][$field_key] = $this->getFormElement($element_type, $element_label, $element_default_value, $element_options, $form, $form_state, $ds_field_name);

            // Handle conditional fields.
            if (isset($field_info['conditions'])) {
              $this->setVisibilityConditions($form['components'][$i][$field_id][$field_key], $field_info['conditions'], $i);
            }

            if ($element_type == 'text_format') {
              $this->textformatFields[] = ['components', $i, $field_id, $field_key];
            }

            if ($element_type == 'image') {
              // Restore parent for other fields.
              $form['#parents'] = $form_parents;
            }
          }
        }
        else {
          $element_type = $field['type'];
          $element_label = t('@field_label', ['@field_label' => $field['label']]);

          $element_default_value = (isset($this->widgetData[$i][$field_id])) ? $this->widgetData[$i][$field_id] : NULL;
          $element_options = isset($field['options']) ? $field['options'] : [];

          $ds_field_name = '';
          if ($element_type == 'image' || $element_type == 'remote_video' || $element_type =='file' || $element_type == 'video') {
            // Save a copy of current parent.
            $form_parents = $form['#parents'] ?? [];
            $form['#parents'] = ['components', $i, $field_id];
            $ds_field_name = [
              str_replace(':', '_', $this->widget),
              $field_id,
              $i,
            ];
            $ds_field_name = implode('_', $ds_field_name);
            // Max 32 chars.
            $ds_field_name = 'f' . substr(md5($ds_field_name), 0, -1);
          }

          $form['components'][$i][$field_id] = $this->getFormElement($element_type, $element_label, $element_default_value, $element_options, $form, $form_state, $ds_field_name, $field_id, $i);

          // Handle conditional fields.
          if (isset($field['conditions'])) {
            $this->setVisibilityConditions($form['components'][$i][$field_id], $field['conditions'], $i);
          }

          if ($element_type == 'text_format') {
            $this->textformatFields[] = ['components', $i, $field_id];
          }
          if ($element_type == 'image' || $element_type == 'remote_video' || $element_type == 'file' || $element_type == 'video') {
            // Restore parent for other fields.
            $form['#parents'] = $form_parents;
          }
        }

      }

      if (
        isset($settings['multiple']) &&
        (bool) $settings['multiple'] === TRUE
      ) {
        $weight_value = (isset($this->widgetData[$i]['_weight'])) ? (int) $this->widgetData[$i]['_weight'] : $i + 1;

        $form['components'][$i]['_weight'] = [
          '#type'          => 'number',
          '#title'         => 'Weight',
          '#min'           => 1,
          '#size'          => 5,
          '#default_value' => $weight_value,
          '#attributes' => [
            'class' => ['df-components-weight'],
          ],
        ];
      }

    }

    // Add more actions.
    if (
      isset($settings['multiple']) &&
      (bool) $settings['multiple'] === TRUE
    ) {

      $form['actions_buttons'] = [
        '#type'          => 'fieldgroup',
        '#title'         => $settings['name'],
        '#title_display' => 'invisible',
      ];

      // Limit add button.
      $display_add_more = (isset($settings['limit']) && $this->widgetRows >= intval($settings['limit'])) ? FALSE : TRUE;

      if ($display_add_more) {
        $form['actions_buttons']['add_name'] = [
          '#type'   => 'submit',
          '#name'   => strtr(ModalEnum::FORM_WIDGET_AJAX_WRAPPER, '-', '_') . '_add_more',
          '#value'  => t('Add one more'),
          '#submit' => ['::addOne'],
          '#ajax'   => [
            'callback' => [$this, 'updateFormCallback'],
            'wrapper'  => ModalEnum::FORM_WIDGET_AJAX_WRAPPER,
          ],
        ];
      }

      // If there is more than one name, add the remove button.
      if ($this->widgetRows > 1) {
        $form['actions_buttons']['remove_name'] = [
          '#type'                    => 'submit',
          '#button_type'             => 'danger',
          '#name'                    => strtr(ModalEnum::FORM_WIDGET_AJAX_WRAPPER, '-', '_') . '_remove_more',
          '#value'                   => t('Remove one'),
          '#limit_validation_errors' => [],
          '#submit'                  => ['::removeOne'],
          '#ajax'                    => [
            'callback' => [$this, 'updateFormCallback'],
            'wrapper'  => ModalEnum::FORM_WIDGET_AJAX_WRAPPER,
          ],
        ];
      }
      // Attach drag and drop DF module library in multiple components case.
      $form['#attached']['library'][] = 'vactory_dynamic_field/drag_and_drop';
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['send'] = [
      '#type'        => 'submit',
      '#button_type' => 'primary',
      '#value'       => $this->t('Save template'),
      '#attributes'  => [
        'class' => [
          'use-ajax',
        ],
      ],
      '#ajax'        => [
        'callback' => [$this, 'submitModalFormAjax'],
        'event'    => 'click',
      ],
    ];

    $form['dialogOptions'] = ['#type' => 'actions'];
    $form['dialogOptions']['data'] = [
      '#type'  => 'hidden',
      '#value' => json_encode($this->widgetData),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWidgetSelectorForm(array &$form, FormStateInterface $form_state) {
    // Get field name.
    $fieldName = \Drupal::request()->query->get('field_name');
    // Get bundle.
    $bundle = \Drupal::request()->query->get('field_bundle');
    // Get the entity target id.
    $entityTypeId = \Drupal::request()->query->get('entity_type_id');
    // Get field definitions.
    $fields = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);

    /** @var \Drupal\field\Entity\FieldConfig $fieldConfig */
    $fieldConfig = $fields[$fieldName] ?? NULL;
    // Get list of allowed providers selected in the field settings.
    $allowedProviders = [];
    if (is_array($fieldConfig->getSetting('allowed_providers'))) {
      $allowedProviders = array_filter($fieldConfig->getSetting('allowed_providers'), function ($value) {
        return $value !== 0;
      });
    }
    // List of widgets.
    $widgets_list = $this->widgetsManager->getModalWidgetsList($allowedProviders);

    $form['#prefix'] = '<div id="' . ModalEnum::FORM_WIDGET_SELECTOR_AJAX_WRAPPER . '">';
    $form['#suffix'] = '</div>';

    // The status messages that will contain any form errors.
    $form['status_messages'] = [
      '#type'   => 'status_messages',
      '#weight' => -10,
    ];

    if ($this->isDropdownSelectMode) {
      // Prepare templates form select options.
      $options = [];
      foreach ($widgets_list as $category => $widgets) {
        if (!isset($category)) {
          $options[$category] = [];
        }

        foreach ($widgets as $widget_id => $widget) {
          $options[$category][$widget['uuid']] = t('@template_name', ['@template_name' => new FormattableMarkup($widget['name'], [])]);
        }
      }
      // A required checkbox field.
      // Select.
      $form['template'] = [
        '#type'         => 'select',
        '#title'        => $this->t('Template'),
        '#options'      => $options,
        '#required'     => TRUE,
        '#empty_option' => $this->t('- Select a template -'),
        '#attributes'   => [
          'class' => ['js-df-template-basic-single'],
        ],
      ];

      // Auto populate fields.
      $form['auto_populate'] = [
        '#type'        => 'checkbox',
        '#title'       => $this->t('Auto populate'),
        '#description' => $this->t('Set default content for this template.'),
      ];
    }
    else {
      // Templates with thumbnails select mode.
      $form['templates_tabs'] = [
        '#type' => 'horizontal_tabs',
        '#group_name' => 'templates_tabs',
      ];
      // Auto populate fields.
      $form['templates_tabs']['auto_populate'] = [
        '#type'        => 'checkbox',
        '#title'       => $this->t('Auto populate'),
        '#description' => $this->t('Set default content for the selected template.'),
      ];

      $renderer = \Drupal::service('renderer');
      foreach ($widgets_list as $category => $widgets) {
        if (!empty($widgets)) {
          if (empty($category)) {
            $category = 'Others';
          }
          if (!isset($form['templates_tabs'][$category])) {
            $form['templates_tabs'][$category] = [
              '#type' => 'details',
              '#title' => $category,
            ];
            if ($category == 'Others') {
              $form['templates_tabs'][$category]['#weight'] = 99;
            }
          }
          $form['templates_tabs'][$category]['search'] = [
            '#type' => 'textfield',
            '#attributes' => [
              'placeholder' => t('Search for a template...'),
              'class' => ['template-filter'],
            ],
          ];
          $options = [];
          if (is_array($widgets) || is_object($widgets)) {
            $file_url_generator = \Drupal::service('file_url_generator');
            foreach ($widgets as $widget_id => $widget) {
              $undefined_screenshot = $this->extensionPathResolver->getPath('module', 'vactory_dynamic_field') . '/images/undefined-screenshot.jpg';
              $widget_preview = [
                '#theme' => 'vactory_dynamic_select_template',
                '#content' => [
                  'screenshot_url' => !empty($widget['screenshot']) ? $widget['screenshot'] : $file_url_generator->generateAbsoluteString($undefined_screenshot),
                  'name' => $widget['name'],
                ],
              ];
              $options[$widget['uuid']] = $renderer->renderPlain($widget_preview);
            }
          }
          $form['templates_tabs'][$category]['template'] = [
            '#type' => 'radios',
            '#options' => $options,
            '#validated' => TRUE,
            '#prefix' => '<div class="select-template-wrapper">',
            '#suffix' => '</div>',
            '#attributes' => [
              'class' => ['select-template-radio'],
            ],
          ];
        }
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['send'] = [
      '#type'        => 'submit',
      '#button_type' => 'primary',
      '#value'       => $this->t('Next'),
      '#submit'      => ['::selectWidget'],
      '#validate'      => ['::selectWidgetValidate'],
      '#ajax'        => [
        'callback' => [$this, 'updateFormCallback'],
        'event'    => 'click',
        'wrapper'  => ModalEnum::FORM_WIDGET_SELECTOR_AJAX_WRAPPER,
      ],
    ];

    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'vactory_dynamic_field/dynamic.libraries.jquery.select2';
    $form['#attached']['library'][] = 'vactory_dynamic_field/dynamic.form_modal';

    return $form;
  }

  /**
   * Validate template choice.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function selectWidgetValidate(array &$form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('template')) && !$this->isDropdownSelectMode) {
      $form_state->setError($form, t('No template has been selected!'));
    }
  }

  /**
   * Submit template choice.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function selectWidget(array &$form, FormStateInterface $form_state) {
    if (!$form_state->hasAnyErrors()) {
      $selected = $form_state->getValue('template');
      $this->widget = $selected;
      $form_state->setRebuild();
    }
  }

  /**
   * Callback for add/remove.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array|\Drupal\Core\Ajax\AjaxResponse
   *   Error or form.
   */
  public function updateFormCallback(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return (new AjaxResponse())->addCommand(new ReplaceCommand('#' . ModalEnum::FORM_WIDGET_SELECTOR_AJAX_WRAPPER, $form));
    }
    // Check if auto populate is checked.
    if ($form_state->getValue('auto_populate') == 1) {
      $widget_id = $this->widget;
      $isDummy = json_encode(['auto-populate' => TRUE]);
      // Pass the selection to the field widget based on the current widget ID.
      $response = new AjaxResponse();
      $response->addCommand(new InvokeCommand("[data-dynamic-widget-value=\"$this->fieldId\"]", 'val', [$isDummy]))
        ->addCommand(new InvokeCommand("[data-dynamic-widget-id=\"$this->fieldId\"]", 'val', [$widget_id]))
        ->addCommand(new CloseDialogCommand(ModalEnum::MODAL_SELECTOR, FALSE));
      if ($this->cardinality == 1) {
        $response->addCommand(new InvokeCommand("[data-dynamic-widget-update=\"$this->fieldId\"]", 'trigger', ['mousedown']));
      }
      else {
        // Case multiple values.
        $response->addCommand(new InvokeCommand("#" . $this->wrapperId, 'addClass', ['update-templates-deltas']));
      }

      if (isset($form['#attached'])) {
        $response->setAttachments($form['#attached']);
      }

      return $response;
    }
    return $form;
  }

  /**
   * AJAX callback handler that displays any errors or a success message.
   */
  public function submitModalFormAjax(array $form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return (new AjaxResponse())->addCommand(new ReplaceCommand('#' . ModalEnum::FORM_WIDGET_AJAX_WRAPPER, $form));
    }

    $data = $form_state->getValue('components');
    $data = json_encode($data);
    $widget_id = $this->widget;

    // Pass the selection to the field widget based on the current widget ID.
    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand("[data-dynamic-widget-value=\"$this->fieldId\"]", 'val', [$data]))
      ->addCommand(new InvokeCommand("[data-dynamic-widget-id=\"$this->fieldId\"]", 'val', [$widget_id]))
      ->addCommand(new CloseDialogCommand(ModalEnum::MODAL_SELECTOR, FALSE));
    if ($this->cardinality == 1) {
      $response->addCommand(new InvokeCommand("[data-dynamic-widget-update=\"$this->fieldId\"]", 'trigger', ['mousedown']));
    }
    else {
      // Case multiple values.
      $response->addCommand(new InvokeCommand("#" . $this->wrapperId, 'addClass', ['update-templates-deltas']));
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    if (!$form_state->hasAnyErrors()) {
      $current = $form_state->get('num_widgets');
      $current++;
      $form_state->set('num_widgets', $current);
      $form_state->setRebuild();
    }
  }

  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function removeOne(array &$form, FormStateInterface $form_state) {
    $current = $form_state->get('num_widgets');
    $current--;
    $form_state->set('num_widgets', $current);
    $form_state->setRebuild();
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $errors = $form_state->getErrors();
    $values = $form_state->getValues();
    $form_errors_updated = FALSE;
    foreach ($this->textformatFields as $textformat_field_parents) {
      $key = implode('][', $textformat_field_parents) . '][value';
      $element = NestedArray::getValue($form, $textformat_field_parents);
      if (isset($errors[$key]) && isset($element['#maxlength']) && is_numeric($element['#maxlength'])) {
        $html_info = NestedArray::getValue($values, $textformat_field_parents);
        $value = strip_tags($html_info['value']);
        if ($value) {
          $value = str_replace([PHP_EOL, "\r"], '', $value);
          if (strlen($value) <= $element['#maxlength']) {
            unset($errors[$key]);
            $form_errors_updated = TRUE;
          }
        }
      }
    }
    if ($form_errors_updated) {
      $form_state->clearErrors();
      foreach ($errors as $key => $message) {
        $form_state->setErrorByName($key, $message);
      }
    }
  }

  /**
   * Manage given elemen states.
   */
  public function setVisibilityConditions(&$element, $conditions, $index = '1') {
    $states = [
      '#states' => [],
    ];
    foreach ($conditions as $state => $state_condition) {
      if (is_array($state_condition)) {
        foreach ($state_condition as $dependent_name => $condition) {
          if (preg_match('/\{(i|index)\}$/', $dependent_name)) {
            $dependent_name = preg_replace('/\{(i|index)\}$/', $index, $dependent_name);
          }
          if (is_array($condition)) {
            $selector = '[name="' . $dependent_name . '"]';
            $states['#states'][$state][$selector] = $condition;
          }
        }
      }
    }
    if (!empty($states['#states'])) {
      $element = array_merge($element, $states);
    }
  }

}
