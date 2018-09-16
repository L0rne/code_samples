<?php

namespace Drupal\custom_csv_export\EventSubscriber;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\Entity\View;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for adding CSV content types to the request.
 */
class CustomCsvSubscriber implements EventSubscriberInterface {

  /**
   * The current route.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRoute;

  /**
   * Constructs a new CsvSubscriber object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route
   *   The current route.
   */
  public function __construct(RouteMatchInterface $current_route = NULL) {
    $this->currentRoute = $current_route;
  }

  /**
   * Register content type formats on the request object.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function onKernelRequest(GetResponseEvent $event) {
    $event->getRequest()->setFormat('csv', array('text/csv'));
  }

  /**
   * Set the Content-Disposition HTTP header.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function onRespond(FilterResponseEvent $event) {
    if ($this->routeIsCsvExport()) {
      // Extract the part of the path we want to use as the filename.
      // Example: "/export/customers" - we want "customers".
      $path      = $event->getRequest()->getPathInfo();
      $filename  = str_replace('/export/' , '', $path);
      $filename  = (empty($filename) ? 'unknown' : $filename);
      $curDate   = new DrupalDateTime('today');
      $filename .= '-' . $curDate->format('Y-m-d') . '.csv';

      // Set the Content-disposition header.
      $response = $event->getResponse();
      $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
  }

  /**
   * Implements \Symfony\Component\EventDispatcher\EventSubscriberInterface::getSubscribedEvents().
   */
  static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][]  = array('onKernelRequest');
    $events[KernelEvents::RESPONSE][] = array('onRespond');
    return $events;
  }

  /**
   * Determines of the current route is a CSV Rest export.
   */
  protected function routeIsCsvExport() {
    // Ensure that we're on a views route.
    if (!$this->currentRoute) {
      return false;
    }
    $route_name = explode('.', $this->currentRoute->getRouteName());
    if ($route_name[0] != 'view') {
      return false;
    }

    // Get the route parameters.
    $params = $this->currentRoute->getParameters();

    // Load the view and get the current display settings.
    $view     = View::load($params->get('view_id'));
    $display  = $view->getDisplay($params->get('display_id'));

    $is_serializer = (
      isset($display['display_options']['style']['type'])
      && $display['display_options']['style']['type'] == 'serializer'
    );

    $is_csv = (
      isset($display['display_options']['style']['options']['formats']['csv'])
      && $display['display_options']['style']['options']['formats']['csv'] == 'csv'
    );

    return $is_serializer && $is_csv;
  }

}
