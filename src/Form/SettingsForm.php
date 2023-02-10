<?php

namespace Drupal\googlecal_sync\Form;

use Drupal\googlecal_sync\services\CalendarImport;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Front Page l10n settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The request Contenxt.
   *
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected $requestContext;

  /**
   * The Calendar Import Service.
   *
   * @var \Drupal\googlecal_sync\services\CalendarImport
   */
  protected $calendarImport;

  /**
   * Construct the SettingsForm Object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Routing\RequestContext $request_context
   *   The request context.
   * @param \Drupal\googlecal_sync\services\CalendarImport $calendarImport
   *   The Calendar Import service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RequestContext $request_context, CalendarImport $calendarImport) {
    parent::__construct($config_factory);

    $this->requestContext = $request_context;
    $this->calendarImport = $calendarImport;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('router.request_context'),
      $container->get('googlecal_sync.import_events')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'googlecal_sync_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['googlecal_sync.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('googlecal_sync.settings');

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authenticate'),
      '#open' => TRUE,
    ];

    $form['auth']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('auth.client_id'),
    ];

    $form['auth']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('auth.client_secret'),
    ];

    $this->calendarImport->validateToken();

    if (!$this->calendarImport->authenticated()) {
      $url = $this->calendarImport->getAuthUrl();

      if ($url) {
        $form['auth']['verification_code'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Verification Code'),
          '#default_value' => '',
        ];

        $form['auth']['button'] = [
          '#type' => 'link',
          '#title' => $this->t('Authenticate your gmail account'),
          '#url' => Url::fromUri($url),
          '#attributes' => [
            'target' => '_blank',
          ],
        ];
      }

    }
    else {
      $calendars = $this->calendarImport->getCalendars();
      if (!empty($calendars)) {
        $form['calendars'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Calendar'),
            $this->t('Available'),
            $this->t('Weight'),
          ],
          '#tabledrag' => [
            [
              'action' => 'order',
              'relationship' => 'sibling',
              'group' => 'table-sort-weight',
            ],
          ],
        ];

        $config_calendar = $config->get('calendars') ?? [];
        $available_calendars = [];
        if (!empty($config_calendar)) {
          foreach ($config_calendar as $calendar) {
            $available_calendars[$calendar['id']] = $calendar['weight'];
          }
        }

        uksort($calendars, function ($previous, $current) use ($available_calendars) {
          if (!isset($available_calendars[$current])) {
            $available_calendars[$current] = strlen($current);
          }
          if (!isset($available_calendars[$previous])) {
            $available_calendars[$previous] = strlen($previous);
          }
          if ($available_calendars[$current] > $available_calendars[$previous]) {
            return -1;
          }
          else {
            return 1;
          }
        });

        $calendar_key = 1;
        foreach ($calendars as $calendar_id => $calendar_name) {
          $calendar_weight = $available_calendars[$calendar_id] ?? $calendar_key;

          $form['calendars'][$calendar_id]['#attributes']['class'][] = 'draggable';
          $form['calendars'][$calendar_id]['#weight'] = $calendar_weight;

          $form['calendars'][$calendar_id]['calendar'] = [
            '#markup' => $calendar_name,
          ];
          $form['calendars'][$calendar_id]['available'] = [
            '#type' => 'checkbox',
            '#title' => '',
            '#default_value' => isset($available_calendars[$calendar_id]),
          ];
          $form['calendars'][$calendar_id]['weight'] = [
            '#type' => 'weight',
            '#title' => $this
              ->t('Weight for @title', [
                '@title' => $calendar_name,
              ]),
            '#title_display' => 'invisible',
            '#default_value' => $calendar_weight,
            // Classify the weight element for #tabledrag.
            '#attributes' => [
              'class' => [
                'table-sort-weight',
              ],
            ],
          ];

          $calendar_key++;
        }
      }
      $form['auth']['rebuild_cache'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Rebuild cached events'),
        '#default_value' => 0,
      ];
      $form['auth']['revoke_account_access'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Revoke Account Access'),
        '#default_value' => 0,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('googlecal_sync.settings');

    $config->set('auth.client_id', $form_state->getValue("client_id"));
    $config->set('auth.client_secret', $form_state->getValue("client_secret"));

    $verification_code = $form_state->getValue("verification_code");
    if (!empty($verification_code)) {
      $config->set('auth.verification_code', $verification_code);
      $accessToken = $this->calendarImport->getAccessToken($verification_code);
      $config->set('auth.access_token', $accessToken);
    }

    // Available calendars.
    $calendars = $this->calendarImport->getCalendars();
    $available_calendars_form = $form_state->getValue('calendars');

    $available_calendars = [];
    if (!empty($available_calendars_form)) {
      foreach ($available_calendars_form as $calendar_id => $checked_value) {
        if ($checked_value['available'] !== 0) {
          $available_calendars[] = [
            'id' => $calendar_id,
            'name' => $calendars[$calendar_id],
            'weight' => $checked_value['weight'],
          ];
        }
      }
    }

    $config->set('calendars', $available_calendars);

    $rebuild_cache = $form_state->getValue("rebuild_cache");
    if ($rebuild_cache) {
      $this->calendarImport->rebuildTodayEvents();
    }

    $revoke_account_access = $form_state->getValue("revoke_account_access");
    if ($revoke_account_access) {
      $config->set('auth.verification_code', '');
      $config->set('auth.access_token', '');
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
