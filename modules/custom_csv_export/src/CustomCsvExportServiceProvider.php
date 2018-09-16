<?php

namespace Drupal\custom_csv_export;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the CSV Serialization Event Subscriber.
 */
class CustomCsvExportServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('csv_serialization.csvsubscriber');
    $definition->setClass('Drupal\custom_csv_export\EventSubscriber\CustomCsvSubscriber');
  }
}
