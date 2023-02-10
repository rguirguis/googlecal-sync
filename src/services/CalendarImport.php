<?php

namespace Drupal\googlecal_sync\services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class CalendarImport.
 *
 * @package Drupal\googlecal_sync\services
 */
class CalendarImport {

  use StringTranslationTrait;

  /**
   * Google Client.
   *
   * @var \Google_Client
   */
  protected $client;

  /**
   * Module Configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * State storage service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Service class of logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerChannelFactory;

  /**
   * Google service is authenticated flag.
   *
   * @var bool
   */
  protected $authenticated = FALSE;

  /**
   * CalendarImport constructor.
   *
   * @param \Google_Client $google_client
   *   Google Client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   State storage service.
   */
  public function __construct(
    \Google_Client $google_client,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    LoggerChannelFactory $loggerChannelFactory
  ) {
    $this->config = $config_factory->get('googlecal_sync.settings');
    $this->client = $this->getClient($google_client);
    $this->state = $state;
    $this->loggerChannelFactory = $loggerChannelFactory;
  }

  /**
   * Validate Client for API calls.
   *
   * @return bool
   *   True if client is valid.
   */
  public function validateClient() {
    $client_id = $this->config->get('auth.client_id');
    $client_secret = $this->config->get('auth.client_secret');

    if (!empty($client_id) && !empty($client_secret)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get valid Google Client.
   *
   * @param mixed $client
   *   Google client service.
   */
  protected function getClient($client) {
    if (!$this->validateClient()) {
      return;
    }
    $client->setApplicationName('Google Calendar API Drupal events.');
    $client->setScopes(\Google_Service_Calendar::CALENDAR_READONLY);
    $client->setClientId($this->config->get('auth.client_id'));
    $client->setClientSecret($this->config->get('auth.client_secret'));
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    $client->setRedirectUri("urn:ietf:wg:oauth:2.0:oob");

    return $client;
  }

  /**
   * Validate Token.
   */
  public function validateToken() {
    if (!$this->client) {
      return;
    }

    $verification_code = $this->config->get('auth.verification_code');
    $access_token = $this->config->get('auth.access_token');

    if (!empty($access_token) && !array_key_exists('error', $access_token)) {
      $this->client->setAccessToken($access_token);
    }
    // If there is no previous token or it's expired.
    if ($this->client->isAccessTokenExpired()) {
      try {
        // Refresh the token if possible, else fetch a new one.
        if ($this->client->getRefreshToken()) {
          $access_token = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
        }
        else {
          if (!empty($verification_code)) {
            $access_token = $this->client->fetchAccessTokenWithAuthCode($verification_code);
          }
        }
      }
      catch (\Exception $e) {
        $this->loggerChannelFactory->get('googlecal_sync')->error($e->getMessage());
        $access_token['error'] = 'Network error';
      }

      // Check to see if there was an error.
      if (is_array($access_token) && !array_key_exists('error', $access_token)) {
        $this->client->setAccessToken($access_token);
        $this->authenticated = TRUE;
      }
    }
    else {
      $this->authenticated = TRUE;
    }
  }

  /**
   * Get Google authorization URL.
   */
  public function getAuthUrl() {
    if (!$this->client) {
      return "";
    }
    return $this->client->createAuthUrl();
  }

  /**
   * Get Access token from activation code.
   *
   * @param string $activation_code
   *   Activation code as returned by the API.
   */
  public function getAccessToken($activation_code) {
    if (!$this->client) {
      return "";
    }
    return $this->client->fetchAccessTokenWithAuthCode($activation_code);
  }

  /**
   * Get all calendars under authorized account.
   *
   * @return array
   *   List of calendars available through the API.
   */
  public function getCalendars() {
    $service = new \Google_Service_Calendar($this->client);
    try {
      $calendars = $service->calendarList->listCalendarList()->getItems();
    }
    catch (\Exception $e) {
      $calendars = [];
    }
    $items = [];

    foreach ($calendars as $calendar) {
      $items[$calendar->getId()] = $calendar->getSummary();
    }

    return $items;
  }

  /**
   *
   */
  public function getAvailableCalendars() {
    return $this->config->get('calendars') ?? [];
  }

  /**
   * Get list of events for each calendar.
   *
   * @param string $calendar_id
   *   Calendar ID.
   * @param array $date_range
   *   Date range as array.
   * @param bool $single_events
   *   Flag to get single events if true.
   * @param int $max_results
   *   Maximum number of results. Default is 10.
   * @param string $order_by
   *   Order by condition.
   *
   * @return array|null
   *   Return array of matching events or NULL.
   *
   * @throws \Exception
   */
  public function getEvents($calendar_id = "default", array $date_range = [], $single_events = TRUE, $max_results = 10, $order_by = "startTime") {
    if (!$this->client) {
      return;
    }
    $service = new \Google_Service_Calendar($this->client);

    $optParams = [
      'maxResults' => $max_results,
      'orderBy' => $order_by,
      'singleEvents' => $single_events,
    ];

    $from = time();
    $to = NULL;

    if (!empty($date_range)) {
      if (is_array($date_range)) {
        if (array_key_exists('from', $date_range)) {
          $from = $date_range['from'];
        }
        if (array_key_exists('to', $date_range)) {
          $to = $date_range['to'];
        }
        if (array_key_exists(0, $date_range)) {
          $from = $date_range[0];
        }
        if (array_key_exists(1, $date_range)) {
          $to = $date_range[1];
        }
      }
      elseif (is_string($date_range)) {
        $from = $date_range;
      }
    }

    if (!empty($from)) {
      $optParams['timeMin'] = date('c', $from);
    }

    if (!empty($to)) {
      $optParams['timeMax'] = date('c', $to);
    }

    $results = $service->events->listEvents($calendar_id, $optParams);
    $events = $results->getItems();

    if (empty($events)) {
      throw new \Exception($this->t('No upcoming events!'));
    }
    else {
      return $this->beautifyEvents($events);
    }

  }

  /**
   * Google client is authenticated.
   *
   * @return bool
   *   Flag if the google client is authenticated.
   */
  public function authenticated() {
    return $this->authenticated;
  }

  /**
   * Normalize the events data.
   *
   * @param array $events
   *   List of event.
   *
   * @return array
   *   Normalized list of events.
   */
  private function beautifyEvents(array $events) {
    $list = [];
    foreach ($events as $event) {
      $start = $event->start->dateTime;
      if (empty($start)) {
        $start = $event->start->date;
      }
      $start_unix = strtotime($start, time());

      $end = $event->end->dateTime;
      if (empty($end)) {
        $end = $event->end->date;
      }
      $end_unix = strtotime($end, time());

      $list[] = [
        'summary' => $event->getSummary(),
        'start' => date("h:i a", $start_unix),
        'end' => date("h:i a", $end_unix),
      ];
    }

    return $list;
  }

  /**
   * Get today events in a list.
   *
   * @param string $calendar_id
   *   Calendar ID.
   * @param bool $force_sync
   *   Flag to force event sync.
   *
   * @return mixed
   *   Array of available events.
   */
  public function getTodayEventsList($calendar_id = "default", $force_sync = FALSE) {
    $state_key = $calendar_id . "_events";
    $from_unix = strtotime('today', time());
    $state_events = $this->state->get($state_key);

    if ($force_sync ||
      (
        is_array($state_events) && (
          !array_key_exists('events', $state_events) ||
          !$state_events['events'] ||
          $state_events['from'] != $from_unix
        )
      )
    ) {

      $to_unix = strtotime("tomorrow", $from_unix);
      try {
        $events = $this->getEvents($calendar_id, [$from_unix, $to_unix]);
      }
      catch (\Exception $e) {
        $events = [];
      }

      $events_list = ['from' => $from_unix, 'events' => $events];

      $this->state->set($state_key, $events_list);
      $state_events = $events_list;
    }

    return $state_events['events'];
  }

  /**
   * Rebuild today events for all calendars.
   */
  public function rebuildTodayEvents() {
    $calendars = $this->getCalendars();
    if (!empty($calendars)) {
      foreach ($calendars as $calendar_id => $calendar) {
        $this->getTodayEventsList($calendar_id, TRUE);
      }
    }
  }

}
