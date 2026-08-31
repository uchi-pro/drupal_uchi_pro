<?php

namespace Drupal\uchi_pro\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uchi_pro\Form\SettingsForm;
use UchiPro\ApiClient;
use UchiPro\Identity;

class DebugController extends ControllerBase {
  public function index(): array {
    $output = '';

    $settings = $this->getSettings();

    $url = $settings->get('url');
    $accessToken = $settings->get('access_token');

    $identity = Identity::createByAccessToken($url, $accessToken);
    $apiClient = ApiClient::create($identity);

    $me = $apiClient->users()->getMe();

    $output .= '<h3>Пользователь</h3>';
    $output .= '<pre>'.print_r($me, true).'</pre>';

    $courses = iterator_to_array($apiClient->courses()->findBy()->getIterator());

    $output .= '<h3>Курсы</h3>';
    $output .= '<pre>'.print_r($courses, true).'</pre>';

    return [
      '#type'   => 'markup',
      '#markup' => $output,
    ];
  }

  private function getSettings()
  {
    return Drupal::config(SettingsForm::SETTINGS);
  }
}
