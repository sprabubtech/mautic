<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
?>
<?php if (!empty($showMore)): ?>
    <a href="<?php echo $view['router']->generate('le_listoptin_index', ['search' => $searchString]); ?>" class="list-group-item" data-toggle="ajax">
        <small class="text-primary"><?php echo $view['translator']->trans('mautic.core.search.more', ['%count%' => $remaining]); ?></small>
    </a>
<?php else: ?>
    <a href="<?php echo $view['router']->generate('le_listoptin_action', ['objectAction' => 'edit', 'objectId' => $listOptin->getId()]); ?>" onclick="Le.closeGlobalSearchResults();" class="list-group-item" data-toggle="ajax">
        <div class="media">
            <div class="media-heading"><?php echo $listOptin->getName(); ?></div>
        </div>
    </a>
<?php endif; ?>