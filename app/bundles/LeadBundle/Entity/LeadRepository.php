<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Entity;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\SearchStringHelper;
use Mautic\LeadBundle\Event\LeadBuildSearchEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\PointBundle\Model\TriggerModel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * LeadRepository.
 */
class LeadRepository extends CommonRepository implements CustomFieldRepositoryInterface
{
    use CustomFieldRepositoryTrait;
    use ExpressionHelperTrait;
    use OperatorListTrait;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var array
     */
    private $availableSocialFields = [];

    /**
     * @var array
     */
    private $availableSearchFields = [];

    /**
     * Required to get the color based on a lead's points.
     *
     * @var TriggerModel
     */
    private $triggerModel;

    /**
     * Used by search functions to search social profiles.
     *
     * @param array $fields
     */
    public function setAvailableSocialFields(array $fields)
    {
        $this->availableSocialFields = $fields;
    }

    /**
     * Used by search functions to search using aliases as commands.
     *
     * @param array $fields
     */
    public function setAvailableSearchFields(array $fields)
    {
        $this->availableSearchFields = $fields;
    }

    /**
     * Sets trigger model.
     *
     * @param TriggerModel $triggerModel
     */
    public function setTriggerModel(TriggerModel $triggerModel)
    {
        $this->triggerModel = $triggerModel;
    }

    /**
     * @param EventDispatcherInterface $dispatcher
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Get a list of leads based on field value.
     *
     * @param $field
     * @param $value
     * @param $ignoreId
     *
     * @return array
     */
    public function getLeadsByFieldValue($field, $value, $ignoreId = null, $indexByColumn = false)
    {
        $col = 'l.'.$field;

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        if (is_array($value)) {
            $q->where(
                $q->expr()->in($col, $value)
            );
        } else {
            $q->where("$col = :search")
                ->setParameter('search', $value);
        }

        if ($ignoreId) {
            $q->andWhere('l.id != :ignoreId')
                ->setParameter('ignoreId', $ignoreId);
        }

        $results = $this->getEntities(['qb' => $q, 'ignore_paginator' => true]);

        if (count($results) && $indexByColumn) {
            /* @var Lead $lead */
            $leads = [];
            foreach ($results as $lead) {
                $fieldKey = $lead->getFieldValue($field);

                $leads[$fieldKey] = $lead;
            }

            return $leads;
        }

        return $results;
    }

    /**
     * @param $email
     *
     * @return Lead[]
     */
    public function getContactsByEmail($email)
    {
        $contacts = $this->getLeadsByFieldValue('email', $email);

        // Attempt to search for contacts without a + suffix
        if (empty($contacts) && preg_match('#^(.*?)\+(.*?)@(.*?)$#', $email, $parts)) {
            $email    = $parts[1].'@'.$parts[3];
            $contacts = $this->getLeadsByFieldValue('email', $email);
        }

        return $contacts;
    }

    /**
     * Get a list of lead entities.
     *
     * @param     $uniqueFieldsWithData
     * @param int $leadId
     * @param int $limit
     *
     * @return array
     */
    public function getLeadsByUniqueFields($uniqueFieldsWithData, $leadId = null, $limit = null)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('l.*')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        // loop through the fields and
        foreach ($uniqueFieldsWithData as $col => $val) {
            $q->orWhere("l.$col = :".$col)
                ->setParameter($col, $val);
        }

        // if we have a lead ID lets use it
        if (!empty($leadId)) {
            // make sure that its not the id we already have
            $q->andWhere('l.id != '.$leadId);
        }

        if ($limit) {
            $q->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        // Collect the IDs
        $leads = [];
        foreach ($results as $r) {
            $leads[$r['id']] = $r;
        }

        // Get entities
        $q = $this->getEntityManager()->createQueryBuilder()
            ->select('l')
            ->from('MauticLeadBundle:Lead', 'l');

        $q->where(
            $q->expr()->in('l.id', ':ids')
        )
            ->setParameter('ids', array_keys($leads))
            ->orderBy('l.dateAdded', 'DESC');
        $entities = $q->getQuery()->getResult();

        /** @var Lead $lead */
        foreach ($entities as $lead) {
            $lead->setAvailableSocialFields($this->availableSocialFields);
            if (!empty($this->triggerModel)) {
                $lead->setColor($this->triggerModel->getColorForLeadPoints($lead->getPoints()));
            }

            $lead->setFields(
                $this->formatFieldValues($leads[$lead->getId()])
            );
        }

        return $entities;
    }

    /**
     * Get list of lead Ids by unique field data.
     *
     * @param $uniqueFieldsWithData is an array of columns & values to filter by
     * @param int $leadId is the current lead id. Added to query to skip and find other leads
     *
     * @return array
     */
    public function getLeadIdsByUniqueFields($uniqueFieldsWithData, $leadId = null)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        // loop through the fields and
        foreach ($uniqueFieldsWithData as $col => $val) {
            $q->orWhere("l.$col = :".$col)
                ->setParameter($col, $val);
        }

        // if we have a lead ID lets use it
        if (!empty($leadId)) {
            // make sure that its not the id we already have
            $q->andWhere('l.id != '.$leadId);
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param string $email
     * @param bool   $all   Set to true to return all matching lead id's
     *
     * @return array|null
     */
    public function getLeadByEmail($email, $all = false)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('email = :search')
            ->setParameter('search', $email);

        $result = $q->execute()->fetchAll();

        if (count($result)) {
            return $all ? $result : $result[0];
        } else {
            return;
        }
    }

    /**
     * Get leads by IP address.
     *
     * @param      $ip
     * @param bool $byId
     *
     * @return array
     */
    public function getLeadsByIp($ip, $byId = false)
    {
        $q = $this->createQueryBuilder('l')
            ->leftJoin('l.ipAddresses', 'i');
        $col = ($byId) ? 'i.id' : 'i.ipAddress';
        $q->where($col.' = :ip')
            ->setParameter('ip', $ip)
            ->orderBy('l.dateAdded', 'DESC');
        $results = $q->getQuery()->getResult();

        /** @var Lead $lead */
        foreach ($results as $lead) {
            $lead->setAvailableSocialFields($this->availableSocialFields);
        }

        return $results;
    }

    /**
     * @param $id
     *
     * @return array
     */
    public function getLead($id)
    {
        $fq = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $fq->select('l.*')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('l.id = '.$id);
        $results = $fq->execute()->fetchAll();

        return (isset($results[0])) ? $results[0] : [];
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     *
     * @return mixed|null
     */
    public function getEntity($id = 0)
    {
        try {
            $q = $this->createQueryBuilder($this->getTableAlias());
            if (is_array($id)) {
                $this->buildSelectClause($q, $id);
                $contactId = (int) $id['id'];
            } else {
                $q->select('l, u, i')
                    ->leftJoin('l.ipAddresses', 'i')
                    ->leftJoin('l.owner', 'u');
                $contactId = $id;
            }
            $q->andWhere($this->getTableAlias().'.id = '.(int) $contactId);
            $entity = $q->getQuery()->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        if ($entity != null) {
            if (!empty($this->triggerModel)) {
                $entity->setColor($this->triggerModel->getColorForLeadPoints($entity->getPoints()));
            }

            $fieldValues = $this->getFieldValues($id);
            $entity->setFields($fieldValues);

            $entity->setAvailableSocialFields($this->availableSocialFields);
        }

        return $entity;
    }

    /**
     * Get a contact entity with the primary company data populated.
     *
     * The primary company data will be a flat array on the entity
     * with a key of `primaryCompany`
     *
     * @param mixed $entity
     *
     * @return mixed|null
     */
    public function getEntityWithPrimaryCompany($entity)
    {
        if (is_int($entity)) {
            $entity = $this->getEntity($entity);
        }

        if ($entity instanceof Lead) {
            $id        = $entity->getId();
            $companies = $this->getEntityManager()->getRepository('MauticLeadBundle:Company')->getCompaniesForContacts([$id]);

            if (!empty($companies[$id])) {
                $primary = null;

                foreach ($companies as $company) {
                    if (isset($company['is_primary']) && $company['is_primary'] == 1) {
                        $primary = $company;
                    }
                }

                if (empty($primary)) {
                    $primary = $companies[$id][0];
                }

                $entity->setPrimaryCompany($primary);
            }
        }

        return $entity;
    }

    /**
     * Get a list of leads.
     *
     * @param array $args
     *
     * @return array
     */
    public function getEntities(array $args = [])
    {
        $contacts = $this->getEntitiesWithCustomFields(
            'lead',
            $args,
            function ($r) {
                if (!empty($this->triggerModel)) {
                    $r->setColor($this->triggerModel->getColorForLeadPoints($r->getPoints()));
                }
                $r->setAvailableSocialFields($this->availableSocialFields);
            }
        );

        $contactCount = isset($contacts['results']) ? count($contacts['results']) : count($contacts);
        if ($contactCount && (!empty($args['withPrimaryCompany']) || !empty($args['withChannelRules']))) {
            $withTotalCount = (array_key_exists('withTotalCount', $args) && $args['withTotalCount']);
            /** @var Lead[] $tmpContacts */
            $tmpContacts = ($withTotalCount) ? $contacts['results'] : $contacts;

            $withCompanies   = !empty($args['withPrimaryCompany']);
            $withPreferences = !empty($args['withChannelRules']);
            $contactIds      = array_keys($tmpContacts);

            if ($withCompanies) {
                $companies = $this->getEntityManager()->getRepository('MauticLeadBundle:Company')->getCompaniesForContacts($contactIds);
            }

            if ($withPreferences) {
                /** @var FrequencyRuleRepository $frequencyRepo */
                $frequencyRepo  = $this->getEntityManager()->getRepository('MauticLeadBundle:FrequencyRule');
                $frequencyRules = $frequencyRepo->getFrequencyRules(null, $contactIds);

                /** @var DoNotContactRepository $dncRepository */
                $dncRepository = $this->getEntityManager()->getRepository('MauticLeadBundle:DoNotContact');
                $dncRules      = $dncRepository->getChannelList(null, $contactIds);
            }

            foreach ($contactIds as $id) {
                if ($withCompanies && isset($companies[$id]) && !empty($companies[$id])) {
                    $primary = null;

                    // Try to find the primary company
                    foreach ($companies[$id] as $company) {
                        if ($company['is_primary'] == 1) {
                            $primary = $company;
                        }
                    }

                    // If no primary was found, just grab the first
                    if (empty($primary)) {
                        $primary = $companies[$id][0];
                    }

                    if (is_array($tmpContacts[$id])) {
                        $tmpContacts[$id]['primaryCompany'] = $primary;
                    } elseif ($tmpContacts[$id] instanceof Lead) {
                        $tmpContacts[$id]->setPrimaryCompany($primary);
                    }
                }

                if ($withPreferences) {
                    $contactFrequencyRules = (isset($frequencyRules[$id])) ? $frequencyRules[$id] : [];
                    $contactDncRules       = (isset($dncRules[$id])) ? $dncRules[$id] : [];

                    $channelRules = Lead::generateChannelRules($contactFrequencyRules, $contactDncRules);
                    if (is_array($tmpContacts[$id])) {
                        $tmpContacts[$id]['channelRules'] = $channelRules;
                    } elseif ($tmpContacts[$id] instanceof Lead) {
                        $tmpContacts[$id]->setChannelRules($channelRules);
                    }
                }
            }

            if ($withTotalCount) {
                $contacts['results'] = $tmpContacts;
            } else {
                $contacts = $tmpContacts;
            }
        }

        return $contacts;
    }

    /**
     * @return array
     */
    public function getFieldGroups()
    {
        return ['core', 'social', 'personal', 'professional'];
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function getEntitiesDbalQueryBuilder()
    {
        $alias = $this->getTableAlias();
        $dq    = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'leads', $alias)
            ->leftJoin($alias, MAUTIC_TABLE_PREFIX.'users', 'u', 'u.id = '.$alias.'.owner_id');

        return $dq;
    }

    /**
     * @param $order
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getEntitiesOrmQueryBuilder($order)
    {
        if (!empty($order)) {
            $order=','.$order;
        }
        $alias = $this->getTableAlias();
        $q     = $this->getEntityManager()->createQueryBuilder();
        $q->select($alias.', u, i'.$order)
            ->from('MauticLeadBundle:Lead', $alias, $alias.'.id')
            ->leftJoin($alias.'.ipAddresses', 'i')
            ->leftJoin($alias.'.owner', 'u')
            ->indexBy($alias, $alias.'.id');

        return $q;
    }

    /**
     * Get contacts for a specific channel entity.
     *
     * @param $args - same as getEntity/getEntities
     * @param        $joinTable
     * @param        $entityId
     * @param array  $filters
     * @param string $entityColumnName
     * @param array  $additionalJoins  [ ['type' => 'join|leftJoin', 'from_alias' => '', 'table' => '', 'condition' => ''], ... ]
     *
     * @return array
     */
    public function getEntityContacts($args, $joinTable, $entityId, $filters = [], $entityColumnName = 'id', array $additionalJoins = null, $contactColumnName = 'lead_id')
    {
        $qb = $this->getEntitiesDbalQueryBuilder();

        if (empty($contactColumnName)) {
            $contactColumnName = 'lead_id';
        }

        $joinCondition = $qb->expr()->andX(
            $qb->expr()->eq($this->getTableAlias().'.id', 'entity.'.$contactColumnName)
        );

        if ($entityId && $entityColumnName) {
            $joinCondition->add(
                $qb->expr()->eq("entity.{$entityColumnName}", (int) $entityId)
            );
        }

        $qb->join(
            $this->getTableAlias(),
            MAUTIC_TABLE_PREFIX.$joinTable,
            'entity',
            $joinCondition
        );

        if (is_array($additionalJoins)) {
            foreach ($additionalJoins as $t) {
                $qb->{$t['type']}(
                    $t['from_alias'],
                    MAUTIC_TABLE_PREFIX.$t['table'],
                    $t['alias'],
                    $t['condition']
                );
            }
        }

        if ($filters) {
            $expr = $qb->expr()->andX();
            foreach ($filters as $column => $value) {
                if (is_array($value)) {
                    $this->buildWhereClauseFromArray($qb, [$value]);
                } else {
                    if (strpos($column, '.') === false) {
                        $column = "entity.$column";
                    }

                    $expr->add(
                        $qb->expr()->eq($column, $qb->createNamedParameter($value))
                    );
                    $qb->andWhere($expr);
                }
            }
        }

        $args['qb'] = $qb;

        return $this->getEntities($args);
    }

    /**
     * Adds the "catch all" where clause to the QueryBuilder.
     *
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addCatchAllWhereClause($q, $filter)
    {
        $customFields = [];
        if (isset($this->customFieldList[0])) {
            $leadFields = $this->customFieldList[0];

            foreach ($leadFields as $key => $field) {
                if (isset($field['is_fixed']) && $field['is_fixed'] == 0) {
                    $customFields[]= 'l.'.$key;
                }
            }
        }

        $columns = array_merge(
            [
                'l.firstname',
                'l.lastname',
                'l.email',
                'l.company_name',
                'l.mobile',
                'l.city',
                'l.state',
                'l.zipcode',
                'l.country',
            ],
            //$this->availableSocialFields
            $customFields
        );
        $unique                 = $this->generateRandomParameterName();
        $xFunc                  = 'orX';
        $expr                   = $q->expr()->$xFunc();
        $dncId                  = $this->getDNCLeadsId();
        $last29daysDateInterval = date('Y-m-d', strtotime('-29 days'));
        $exprFunc               ='gte';
        if ($filter->string == 'recentlyaddedleads') {
            $columns               = ['l.date_added'];

            foreach ($columns as $col) {
                $expr->add(
                   $q->expr()->$exprFunc($col, ":$unique")
               );
            }

            return [
               $expr,
               ["$unique" => $last29daysDateInterval],
           ];
        } elseif ($filter->string == 'activeleads') {
            $columns               = ['l.last_active'];

            foreach ($columns as $col) {
                $expr->add(
                    $q->expr()->$exprFunc($col, ":$unique")
                );
            }

            return [
                $expr,
                ["$unique" => $last29daysDateInterval],
            ];
        } elseif ($filter->string == 'donotcontact' && !empty($dncId)) {
            $ids = implode(',', $dncId);
            $q->select('l.*');
            $q->where($q->expr()->in('l.id', $ids));
        } elseif ($filter->string == 'inactiveleads') {
            $q->select('l.*');
            $q->where($q->expr()->in('l.status', ['3', '4', '5', '6']));
        } elseif ($filter->string == 'active_leads') {
            $q->select('l.*');
            $q->where($q->expr()->in('l.status', ['1']));
        } elseif ($filter->string == 'engaged_leads') {
            $q->select('l.*');
            $q->where($q->expr()->in('l.status', ['2']));
        } else {
            return $this->addStandardCatchAllWhereClause($q, $filter, $columns);
        }
    }

    /**
     * Adds the command where clause to the QueryBuilder.
     *
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addSearchCommandWhereClause($q, $filter)
    {
        $command                 = $filter->command;
        $string                  = $filter->string;
        $unique                  = $this->generateRandomParameterName();
        $returnParameter         = false; //returning a parameter that is not used will lead to a Doctrine error
        list($expr, $parameters) = parent::addSearchCommandWhereClause($q, $filter);

        //DBAL QueryBuilder does not have an expr()->not() function; boo!!

        // This will be switched by some commands that use join tables as NOT EXISTS queries will be used
        $exprType = ($filter->not) ? 'negate_expr' : 'expr';

        $operators = $this->getFilterExpressionFunctions();
        $operators = array_merge($operators, [
            'x' => [
                'expr'        => 'andX',
                'negate_expr' => 'orX',
            ],
            'null' => [
                'expr'        => 'isNull',
                'negate_expr' => 'isNotNull',
            ],
        ]);

        $innerJoinTables = (isset($this->advancedFilterCommands[$command])
            && SearchStringHelper::COMMAND_NEGATE !== $this->advancedFilterCommands[$command]);
        $likeExpr = $operators['like'][$exprType];
        $eqExpr   = $operators['='][$exprType];
        $nullExpr = $operators['null'][$exprType];
        $inExpr   = $operators['in'][$exprType];
        $xExpr    = $operators['x'][$exprType];

        switch ($command) {
            case $this->translator->trans('le.lead.lead.searchcommand.isanonymous'):
            case $this->translator->trans('le.lead.lead.searchcommand.isanonymous', [], null, 'en_US'):
                $expr = $q->expr()->$nullExpr('l.date_identified');
                break;
            case $this->translator->trans('mautic.core.searchcommand.ismine'):
            case $this->translator->trans('mautic.core.searchcommand.ismine', [], null, 'en_US'):
                $expr = $q->expr()->$eqExpr('l.owner_id', $this->currentUser->getId());
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.isunowned'):
            case $this->translator->trans('le.lead.lead.searchcommand.isunowned', [], null, 'en_US'):
                $expr = $q->expr()->orX(
                    $q->expr()->$eqExpr('l.owner_id', 0),
                    $q->expr()->$nullExpr('l.owner_id')
                );
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.owner'):
            case $this->translator->trans('le.lead.lead.searchcommand.owner', [], null, 'en_US'):
                $expr = $q->expr()->orX(
                    $q->expr()->$likeExpr('u.first_name', ':'.$unique),
                    $q->expr()->$likeExpr('u.last_name', ':'.$unique)
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('mautic.core.searchcommand.name'):
            case $this->translator->trans('mautic.core.searchcommand.name', [], null, 'en_US'):
                $expr = $q->expr()->orX(
                    $q->expr()->$likeExpr('l.firstname', ":$unique"),
                    $q->expr()->$likeExpr('l.lastname', ":$unique")
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('mautic.core.searchcommand.email'):
            case $this->translator->trans('mautic.core.searchcommand.email', [], null, 'en_US'):
                $expr            = $q->expr()->$likeExpr('l.email', ":$unique");
                $returnParameter = true;
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.list'):
            case $this->translator->trans('le.lead.lead.searchcommand.list', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_lists_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_lists',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.alias', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0)
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.list.active'):
            case $this->translator->trans('le.lead.lead.searchcommand.list.active', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_lists_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_lists',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.alias', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$inExpr('l.status', ['1', '2'])
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.list.inactive'):
            case $this->translator->trans('le.lead.lead.searchcommand.list.inactive', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_lists_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_lists',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.alias', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$inExpr('l.status', ['3', '4', '5', '6'])
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.tag'):
            case $this->translator->trans('le.lead.lead.searchcommand.tag', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_tags_xref',
                            'alias'      => 'xtag',
                            'condition'  => 'l.id = xtag.lead_id',
                        ],
                        [
                            'from_alias' => 'xtag',
                            'table'      => 'lead_tags',
                            'alias'      => 'tag',
                            'condition'  => 'xtag.tag_id = tag.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'tag.id', $eqExpr, $unique, null),
                    null,
                    $filter
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.tag.active'):
            case $this->translator->trans('le.lead.lead.searchcommand.tag.active', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_tags_xref',
                            'alias'      => 'xtag',
                            'condition'  => 'l.id = xtag.lead_id',
                        ],
                        [
                            'from_alias' => 'xtag',
                            'table'      => 'lead_tags',
                            'alias'      => 'tag',
                            'condition'  => 'xtag.tag_id = tag.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'tag.id', $eqExpr, $unique, null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$inExpr('l.status', ['1', '2'])
                        )),
                    null,
                    $filter
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.tag.inactive'):
            case $this->translator->trans('le.lead.lead.searchcommand.tag.inactive', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_tags_xref',
                            'alias'      => 'xtag',
                            'condition'  => 'l.id = xtag.lead_id',
                        ],
                        [
                            'from_alias' => 'xtag',
                            'table'      => 'lead_tags',
                            'alias'      => 'tag',
                            'condition'  => 'xtag.tag_id = tag.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'tag.id', $eqExpr, $unique, null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$inExpr('l.status', ['3', '4', '5', '6'])
                        )),
                    null,
                    $filter
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin'):
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0)
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.confirm'):
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.confirm', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$eqExpr('list_lead.confirmed_lead', 1)
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.unconfirm'):
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.unconfirm', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$eqExpr('list_lead.unconfirmed_lead', 1)
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.unsubscribe'):
            case $this->translator->trans('le.lead.lead.searchcommand.listoptin.unsubscribe', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$eqExpr('list_lead.unsubscribed_lead', 1)
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.list.optin.active'):
            case $this->translator->trans('le.lead.lead.searchcommand.list.optin.active', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$inExpr('l.status', ['1', '2'])
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.list.optin.inactive'):
            case $this->translator->trans('le.lead.lead.searchcommand.list.optin.inactive', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_listoptin_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_listoptin',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'list.id', $eqExpr, $unique, ($filter->not) ? true : null,
                        // orX for filter->not either manuall removed or is null
                        $q->expr()->$xExpr(
                            $q->expr()->$eqExpr('list_lead.manually_removed', 0),
                            $q->expr()->$inExpr('l.status', ['3', '4', '5', '6'])
                        )
                    ),
                    null,
                    $filter
                );
                $filter->strict  = true;
                $returnParameter = true;

                break;
            case $this->translator->trans('mautic.core.searchcommand.ip'):
            case $this->translator->trans('mautic.core.searchcommand.ip', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_ips_xref',
                            'alias'      => 'ip_lead',
                            'condition'  => 'l.id = ip_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'ip_lead',
                            'table'      => 'ip_addresses',
                            'alias'      => 'ip',
                            'condition'  => 'ip_lead.ip_id = ip.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'ip.ip_address', $likeExpr, $unique, null)
                );
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.duplicate'):
            case $this->translator->trans('le.lead.lead.searchcommand.duplicate', [], null, 'en_US'):
                $prateek  = explode('+', $string);
                $imploder = [];

                foreach ($prateek as $key => $value) {
                    $list       = $this->getEntityManager()->getRepository('MauticLeadBundle:LeadList')->findOneByAlias($value);
                    $imploder[] = ((!empty($list)) ? (int) $list->getId() : 0);
                }

                //logic. In query, Sum(manually_removed) should be less than the current)
                $pluck    = count($imploder);
                $imploder = (string) (implode(',', $imploder));

                $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();
                $sq->select('duplicate.lead_id')
                    ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'duplicate')
                    ->where(
                        $q->expr()->andX(
                            $q->expr()->in('duplicate.leadlist_id', $imploder),
                            $q->expr()->eq('duplicate.manually_removed', 0)
                        )
                    )
                    ->groupBy('duplicate.lead_id')
                    ->having("COUNT(duplicate.lead_id) = $pluck");

                $expr            = $q->expr()->$inExpr('l.id', sprintf('(%s)', $sq->getSQL()));
                $returnParameter = true;

                break;
            case $this->translator->trans('le.lead.lead.searchcommand.company'):
            case $this->translator->trans('le.lead.lead.searchcommand.company', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'companies_leads',
                            'alias'      => 'comp_lead',
                            'condition'  => 'l.id = comp_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'comp_lead',
                            'table'      => 'companies',
                            'alias'      => 'comp',
                            'condition'  => 'comp_lead.company_id = comp.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 'comp.companyname', $likeExpr, $unique, null)
                );
                $returnParameter = true;
                break;
            case $this->translator->trans('le.lead.lead.searchcommand.stage'):
            case $this->translator->trans('le.lead.lead.searchcommand.stage', [], null, 'en_US'):
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'stages',
                            'alias'      => 's',
                            'condition'  => 'l.stage_id = s.id',
                        ],
                    ],
                    $innerJoinTables,
                    $this->generateFilterExpression($q, 's.name', $likeExpr, $unique, null)
                );
                $returnParameter = true;
                break;
            default:
                if (in_array($command, $this->availableSearchFields)) {
                    $expr = $q->expr()->$likeExpr("l.$command", ":$unique");
                }
                $returnParameter = true;
                break;
        }

        if ($this->dispatcher) {
            $event = new LeadBuildSearchEvent($filter->string, $filter->command, $unique, $filter->not, $q);
            $this->dispatcher->dispatch(LeadEvents::LEAD_BUILD_SEARCH_COMMANDS, $event);
            if ($event->isSearchDone()) {
                $returnParameter = $event->getReturnParameters();
                $filter->strict  = $event->getStrict();
                $expr            = $event->getSubQuery();
                $parameters      = array_merge($parameters, $event->getParameters());
            }
        }

        if ($returnParameter) {
            $string              = ($filter->strict) ? $filter->string : "{$filter->string}%";
            $parameters[$unique] = $string;
        }

        return [
            $expr,
            $parameters,
        ];
    }

    /**
     * Returns the array of search commands.
     *
     * @return array
     */
    public function getSearchCommands()
    {
        $commands = [
            'le.lead.lead.searchcommand.isanonymous',
            'mautic.core.searchcommand.ismine',
            'le.lead.lead.searchcommand.isunowned',
            'le.lead.lead.searchcommand.list',
            'le.lead.lead.searchcommand.list.active',
            'le.lead.lead.searchcommand.list.inactive',
            'le.lead.lead.searchcommand.listoptin',
            'le.lead.lead.searchcommand.listoptin.confirm',
            'le.lead.lead.searchcommand.listoptin.unconfirm',
            'le.lead.lead.searchcommand.listoptin.unsubscribe',
            'le.lead.lead.searchcommand.list.optin.active',
            'le.lead.lead.searchcommand.list.optin.inactive',
            'mautic.core.searchcommand.name',
            'mautic.lead.lead.searchcommand.company_new',
            'mautic.core.searchcommand.email',
            'le.lead.lead.searchcommand.owner',
            'mautic.core.searchcommand.ip',
            'le.lead.lead.searchcommand.tag',
            'le.lead.lead.searchcommand.tag.active',
            'le.lead.lead.searchcommand.tag.inactive',
            'mautic.core.searchcommand.status.inactive',
            'mautic.core.searchcommand.status.active',
            'mautic.core.searchcommand.status.engaged',
            'le.lead.lead.searchcommand.stage',
            'le.lead.lead.searchcommand.duplicate',
            'le.lead.lead.searchcommand.drip_scheduled',
            'le.lead.lead.searchcommand.email_sent',
            'le.lead.lead.searchcommand.email_read',
            'le.lead.lead.searchcommand.email_click',
            'le.lead.lead.searchcommand.email_queued',
            'le.lead.lead.searchcommand.email_pending',
            'le.lead.lead.searchcommand.email_failure',
            'le.lead.lead.searchcommand.email_unsubscribe',
            'le.lead.lead.searchcommand.email_bounce',
            'le.lead.lead.searchcommand.email_spam',
            'le.lead.lead.searchcommand.sms_sent',
            'le.lead.lead.searchcommand.web_sent',
            'le.lead.lead.searchcommand.mobile_sent',
            'le.lead.campaign.searchcommand.wf-progress',
            'le.lead.campaign.searchcommand.wf-completed',
            'le.lead.campaign.searchcommand.wf-goal',
            'le.lead.drip.searchcommand.lead',
            'le.lead.drip.searchcommand.pending',
            'le.lead.drip.searchcommand.sent',
            'le.lead.drip.searchcommand.click',
            'le.lead.drip.searchcommand.read',
            'le.lead.drip.searchcommand.unsubscribe',
            'le.lead.drip.searchcommand.bounce',
            'le.lead.drip.searchcommand.failed',
            'le.lead.lead.searchcommand.email_churn',
            'le.lead.drip.searchcommand.churn',
        ];

        if (!empty($this->availableSearchFields)) {
            $commands = array_merge($commands, $this->availableSearchFields);
        }

        return array_merge($commands, parent::getSearchCommands());
    }

    /**
     * Returns the array of columns with the default order.
     *
     * @return array
     */
    protected function getDefaultOrder()
    {
        return [
            ['l.last_active', 'DESC'],
        ];
    }

    /**
     * Updates lead's lastActive with now date/time.
     *
     * @param int $leadId
     */
    public function updateLastActive($leadId, $updateEngage=false)
    {
        $dt     = new DateTimeHelper();
        if ($updateEngage) {
            $fields = ['last_active' => $dt->toUtcString(), 'status' => 2]; //update Lead Status as Engaged
        } else {
            $fields = ['last_active' => $dt->toUtcString()]; //update LastActive only
        }

        $this->getEntityManager()->getConnection()->update(MAUTIC_TABLE_PREFIX.'leads', $fields, ['id' => $leadId]);
    }

    /**
     * Gets the ID of the latest ID.
     *
     * @return int
     */
    public function getMaxLeadId()
    {
        $result = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('max(id) as max_lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->execute()->fetchAll();

        return $result[0]['max_lead_id'];
    }

    /**
     * Gets names, signature and email of the user(lead owner).
     *
     * @param int $ownerId
     *
     * @return array|false
     */
    public function getLeadOwner($ownerId)
    {
        if (!$ownerId) {
            return false;
        }

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('u.id, u.first_name, u.last_name, u.email, u.mobile ,u.signature')
            ->from(MAUTIC_TABLE_PREFIX.'users', 'u')
            ->where('u.id = :ownerId')
            ->setParameter('ownerId', (int) $ownerId);

        $result = $q->execute()->fetch();

        // Fix the HTML markup
        if (is_array($result)) {
            foreach ($result as &$field) {
                $field = html_entity_decode($field);
            }
        }

        return $result;
    }

    /**
     * Check lead owner.
     *
     * @param Lead  $lead
     * @param array $ownerIds
     *
     * @return array|false
     */
    public function checkLeadOwner(Lead $lead, $ownerIds = [])
    {
        if (empty($ownerIds)) {
            return false;
        }

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('u.id')
            ->from(MAUTIC_TABLE_PREFIX.'users', 'u')
            ->join('u', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.owner_id = u.id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->in('u.id', ':ownerIds'),
                    $q->expr()->eq('l.id', ':leadId')
                )
            )
            ->setParameter('ownerIds', implode(',', $ownerIds))
            ->setParameter('leadId', $lead->getId());

        return (bool) $q->execute()->fetchColumn();
    }

    /**
     * @param array $contactIds
     *
     * @return array
     */
    public function getContacts(array $contactIds)
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $qb->select('l.*')->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where(
                $qb->expr()->in('l.id', $contactIds)
            );

        $results = $qb->execute()->fetchAll();

        if ($results) {
            $contacts = [];
            foreach ($results as $result) {
                $contacts[$result['id']] = $result;
            }

            return $contacts;
        }

        return [];
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'l';
    }

    /**
     * @param QueryBuilder $q
     * @param array        $tables          $tables[0] should be primary table
     * @param bool         $innerJoinTables
     * @param null         $whereExpression
     * @param null         $having
     * @param              $filter
     */
    public function applySearchQueryRelationship(QueryBuilder $q, array $tables, $innerJoinTables, $whereExpression = null, $having = null, $filter = null)
    {
        $primaryTable = $tables[0];
        unset($tables[0]);
        $joinType = ($innerJoinTables) ? 'join' : 'leftJoin';

        $this->useDistinctCount = true;
        $joins                  = $q->getQueryPart('join');
        if (!array_key_exists($primaryTable['alias'], $joins)) {
            $q->$joinType(
                $primaryTable['from_alias'],
                MAUTIC_TABLE_PREFIX.$primaryTable['table'],
                $primaryTable['alias'],
                $primaryTable['condition']
            );
            foreach ($tables as $table) {
                $q->$joinType($table['from_alias'], MAUTIC_TABLE_PREFIX.$table['table'], $table['alias'], $table['condition']);
            }

            if ($whereExpression) {
                $q->andWhere($whereExpression);
            }

            if ($having) {
                $q->andHaving($having);
            }
            $q->groupBy('l.id');
        } elseif (array_key_exists($primaryTable['alias'], $joins) && $filter != null) {
            if ($whereExpression && strtolower($filter->type) == 'or') {
                $q->orWhere($whereExpression);
            } elseif ($whereExpression) {
                $q->andWhere($whereExpression);
            }
        }
    }

    /**
     * @param array $changes
     * @param       $id
     * @param int   $tries
     */
    protected function updateContactPoints(array $changes, $id, $tries = 1)
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->update(MAUTIC_TABLE_PREFIX.'leads')
            ->where('id = '.$id);

        $ph = 0;
        // Keep operator in same order as was used in Lead::adjustPoints() in order to be congruent with what was calculated in PHP
        // Again ignoring Aunt Sally here (PEMDAS)
        foreach ($changes as $operator => $points) {
            $qb->set('points', 'points '.$operator.' :points'.$ph)
                ->setParameter('points'.$ph, $points, \PDO::PARAM_INT);

            ++$ph;
        }

        try {
            $qb->execute();
        } catch (DriverException $exception) {
            $message = $exception->getMessage();

            if (strpos($message, 'Deadlock') !== false && $tries <= 3) {
                ++$tries;

                $this->updateContactPoints($changes, $id, $tries);
            }
        }

        // Query new points
        return (int) $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('l.points')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('l.id = '.$id)
            ->execute()
            ->fetchColumn();
    }

    /**
     * @param Lead $entity
     */
    protected function postSaveEntity($entity)
    {
        // Check if points need to be appended
        if ($entity->getPointChanges()) {
            $newPoints = $this->updateContactPoints($entity->getPointChanges(), $entity->getId());

            // Set actual points so that code using getPoints knows the true value
            $entity->setActualPoints($newPoints);

            $changes = $entity->getChanges();

            if (isset($changes['points'])) {
                // Let's adjust the points to be more accurate in the change log
                $changes['points'][1] = $newPoints;
                $entity->setChanges($changes);
            }
        }
    }

    public function getPageHitDetails($contactId)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('p.url', 'count(id) as pagehits')
            ->from(MAUTIC_TABLE_PREFIX.'page_hits', 'p');

        if ($contactId !== null) {
            $q->andWhere(
                $q->expr()->eq('p.lead_id', $contactId)
            );
            $q->groupBy('p.url');
            $q->orderBy('count(id)', 'DESC');
        }
        //get a total number of sent emails
        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function updateContactScore($score, $id)
    {
        $now = new \DateTime();
        $q   = $this->_em->getConnection()->createQueryBuilder();
        $q->update(MAUTIC_TABLE_PREFIX.'leads')
            ->set('score', ':score')
            ->set('date_modified', ':datemodified')
            ->where('id = '.$id)
            ->setParameter('score', $score)
            ->setParameter('datemodified', $now->format('Y-m-d H:i:s'))->execute();
    }

    public function getHotAndWarmLead($dateinterval)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('l.score as leadscore', 'l.id as leadid')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('l.score in ("hot","warm")')
            ->andWhere('l.last_active <= '."'".$dateinterval."'");

        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function getActiveLeads($dateinterval)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('l.id as leadid')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->andWhere($q->expr()->eq('l.status', ':status'))->setParameter('status', ' ')->orWhere($q->expr()->isNull('l.status'))->orWhere($q->expr()->in('l.status', [2]))
            ->andWhere('l.last_active <= '."'".$dateinterval."'");
        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function getActiveEngagedLeads($dateinterval)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('l.id as leadid')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->Where('l.last_active > '."'".$dateinterval."'")
            ->andWhere('l.status in (1)');
        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function updateLeadStatus($leadid, $status)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->update(MAUTIC_TABLE_PREFIX.'leads')
            ->set('status', ':status')
            ->setParameter('status', $status)
            ->where(
                $q->expr()->in('id', $leadid)
            )
            ->execute();
    }

    public function getRecentlyAddedLeadsCount($viewOthers = false)
    {
        $q                    = $this->_em->getConnection()->createQueryBuilder();
        $last30daysAddedLeads = date('Y-m-d', strtotime('-29 days'));

        $q->select('count(*) as recentlyadded')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere($q->expr()->isNull('l.created_by'));
        }
        $q->andWhere($q->expr()->gte('l.date_added', ':dateAdded'))
            ->setParameter('dateAdded', $last30daysAddedLeads);
        $results = $q->execute()->fetchAll();
        //dump($q->getSQL());
        //      dump($last7daysAddedLeads);

        return $results[0]['recentlyadded'];
    }

    public function getEngagedLeadsCount($viewOthers = false)
    {
        $q                    = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as engaged')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere($q->expr()->isNull('l.created_by'));
        }
        $q->andWhere($q->expr()->eq('l.status', 2));
        $results = $q->execute()->fetchAll();

        return $results[0]['engaged'];
    }

    public function getInActiveLeadsCount($viewOthers = false)
    {
        $q                    = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as inactive')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere($q->expr()->isNull('l.created_by'));
        }
        $statusArr   = [];
        $statusArr[] = 3;
        $statusArr[] = 4;
        $statusArr[] = 5;
        $statusArr[] = 6;
        $q->andWhere($q->expr()->in('l.status', $statusArr));
        $results = $q->execute()->fetchAll();

        return $results[0]['inactive'];
    }

    public function getTotalLeadsCount($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as allleads')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        if (!$viewOthers && $this->currentUser != null) {
            $q->andWhere($q->expr()->eq('l.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser != null && $this->currentUser->getId() != 1 && $viewOthers) {
            /*$q->andWhere($q->expr()->neq('l.owner_id', ':id'))
                ->setParameter('id', '1');*/
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere('l.created_by  IS NULL');
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['allleads'];
    }

    public function getActiveLeadCount($viewOthers = false)
    {
        $last30daysActiveLeads = date('Y-m-d', strtotime('-29 days'));

        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as activeleads')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere($q->expr()->isNull('l.created_by'));
        }
        $q->andWhere($q->expr()->eq('l.status', 1));
        $results = $q->execute()->fetchAll();

        return $results[0]['activeleads'];
    }

    public function getDoNotContactLeadsCount($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as donotcontact')
            ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', 'dnc')
            ->leftJoin('dnc', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = dnc.lead_id');

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('l.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
            $q->orWhere($q->expr()->isNull('l.created_by'));
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['donotcontact'];
    }

    public function getDNCLeadsId()
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('dnc.lead_id as leadid')
            ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', 'dnc');

        $results = $q->execute()->fetchAll();
        $dncIds  = [];
        foreach ($results as $key => $value) {
            $dncIds[] = $value['leadid'];
        }

        return $dncIds;
    }

    /**
     * Checks to ensure that a email is unique.
     *
     * @param $params
     *
     * @return array
     */
    public function checkUniqueEmail($email, $id)
    {
        $q      = $this->createQueryBuilder('l');
        $result = [];
        if (!empty($email)) {
            $q->where('l.email = :email')
                ->setParameter('email', $email);
            if ($id != null) {
                $q->andWhere('l.id != :id')
                    ->setParameter('id', $id);
            }
            $result = $q->getQuery()->getResult();
        }

        return $result;
    }

    /**
     * Checks to ensure that a username and/or email is unique.
     *
     * @param $params
     *
     * @return array
     */
    public function checkUniqueUsernameEmail($params)
    {
        $q = $this->createQueryBuilder('l');
        if (isset($params['email'])) {
            $q->where('l.email = :email')
                ->setParameter('email', $params['email']);
        }

        return $q->getQuery()->getResult();
    }
}
