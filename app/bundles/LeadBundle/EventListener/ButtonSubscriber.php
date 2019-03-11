<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Templating\Helper\ButtonHelper;

class ButtonSubscriber extends CommonSubscriber
{
    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    /**
     * @param CustomButtonEvent $event
     */
    public function injectViewButtons(CustomButtonEvent $event)
    {
        if (0 === strpos($event->getRoute(), 'le_contact_index')) {
            $exportRoute = $this->router->generate(
                'le_contact_action',
                ['objectAction' => 'batchExport']
            );

            $event->addButton(
                [
                    'attr' => [
                        'data-toggle'           => 'confirmation',
                        'href'                  => $exportRoute,
                        'data-precheck'         => 'batchActionPrecheck',
                        'data-message'          => $this->translator->trans('mautic.core.export.items', ['%items%' => 'Leads']),
                        'data-confirm-text'     => $this->translator->trans('mautic.core.export'),
                        'data-confirm-callback' => 'executeBatchAction',
                        'data-cancel-text'      => $this->translator->trans('mautic.core.form.cancel'),
                        'data-cancel-callback'  => 'dismissConfirmation',
                    ],
                    'btnText'   => $this->translator->trans('mautic.core.export'),
                    'iconClass' => 'fa fa-download',
                ],
                ButtonHelper::LOCATION_BULK_ACTIONS
            );

            $event->addButton(
                ['buttons',
                    'attr' => [
                        'href' => $this->router->generate('le_import_action', ['objectAction' => 'new']),
                    ],
                    //'iconClass' => 'fa fa-upload',
                    'btnText'   => 'le.lead.lead.import',
                    'priority'  => 3,
                ],
                ButtonHelper::LOCATION_PAGE_ACTIONS
            );
            $event->addButton(
                ['buttons',
                    'attr' => [
                        'href'          => $exportRoute,
                        'onclick'       => 'Le.exportLeads()',
                        'data-toggle'   => null,
                    ],
                    'btnText'   => $this->translator->trans('mautic.core.export'),
                    //'iconClass' => 'fa fa-download btn-nospin',
                    'priority'  => 2,
                ],
                ButtonHelper::LOCATION_PAGE_ACTIONS
            );
            $event->addButton(
            ['buttons',
                'attr' => [
                    'href' => $this->router->generate('le_contactfield_index'),
                ],
                //'iconClass' => 'fa fa-cog',
                'btnText'   => 'le.lead.field.menu.index',
                'priority'  => 1,
            ],
                ButtonHelper::LOCATION_PAGE_ACTIONS
            );
        }
    }
}
