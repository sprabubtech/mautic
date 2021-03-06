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

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\TimeType;
use Doctrine\ORM\PersistentCollection;
use Mautic\CoreBundle\Doctrine\QueryFormatter\AbstractFormatter;
use Mautic\CoreBundle\Doctrine\Type\UTCDateTimeType;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Event\LeadListFilteringEvent;
use Mautic\LeadBundle\Event\LeadListFiltersOperatorsEvent;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * LeadListRepository.
 */
class LeadListRepository extends CommonRepository
{
    use OperatorListTrait;
    use ExpressionHelperTrait;
    use RegexTrait;

    /**
     * @var bool
     */
    protected $listFiltersInnerJoinCompany = false;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * Flag to check if some segment filter on a company field exists.
     *
     * @var bool
     */
    protected $hasCompanyFilter = false;

    /**
     * @var \Doctrine\DBAL\Schema\Column[]
     */
    protected $leadTableSchema;

    /**
     * @var \Doctrine\DBAL\Schema\Column[]
     */
    protected $companyTableSchema;

    protected $checkleadlist = false;

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
            $entity = $this
                ->createQueryBuilder('l')
                ->where('l.id = :listId')
                ->setParameter('listId', $id)
                ->getQuery()
                ->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        return $entity;
    }

    /**
     * Get a list of lists.
     *
     * @param bool   $user
     * @param string $alias
     * @param string $id
     *
     * @return array
     */
    public function getLists($user = false, $alias = '', $id = '')
    {
        static $lists = [];

        if (is_object($user)) {
            $user = $user->getId();
        }

        $key = (int) $user.$alias.$id;
        if (isset($lists[$key])) {
            return $lists[$key];
        }

        $q = $this->getEntityManager()->createQueryBuilder()
            ->from('MauticLeadBundle:LeadList', 'l', 'l.id');

        $q->select('partial l.{id, name, alias}')
            ->andWhere($q->expr()->eq('l.isPublished', ':true'))
            ->setParameter('true', true, 'boolean');

        if (!empty($user)) {
            $q->andWhere($q->expr()->eq('l.isGlobal', ':true'));
            $q->orWhere('l.createdBy = :user');
            $q->setParameter('user', $user);
        } else {
            if ($this->currentUser != null && !$this->currentUser->isAdmin()) {
                $q->andWhere('l.createdBy != 1');
            }
        }

        if (!empty($alias)) {
            $q->andWhere('l.alias = :alias');
            $q->setParameter('alias', $alias);
        }

        if (!empty($id)) {
            $q->andWhere(
                $q->expr()->neq('l.id', $id)
            );
        }

        $q->orderBy('l.name');

        $results = $q->getQuery()->getArrayResult();

        $lists[$key] = $results;

        return $results;
    }

    /**
     * Get lists for a specific lead.
     *
     * @param      $lead
     * @param bool $forList
     * @param bool $singleArrayHydration
     * @param bool $isPublic
     *
     * @return mixed
     */
    public function getLeadLists($lead, $forList = false, $singleArrayHydration = false, $isPublic = false)
    {
        if (is_array($lead)) {
            $q = $this->getEntityManager()->createQueryBuilder()
                ->from('MauticLeadBundle:LeadList', 'l', 'l.id');

            if ($forList) {
                $q->select('partial l.{id, alias, name}, partial il.{lead, list, dateAdded, manuallyAdded, manuallyRemoved}');
            } else {
                $q->select('l, partial lead.{id}');
            }

            $q->leftJoin('l.leads', 'il')
                ->leftJoin('il.lead', 'lead');

            $q->where(
                $q->expr()->andX(
                    $q->expr()->in('lead.id', ':leads'),
                    $q->expr()->in('il.manuallyRemoved', ':false')
                )
            )
                ->setParameter('leads', $lead)
                ->setParameter('false', false, 'boolean');
            if ($isPublic) {
                $q->andWhere($q->expr()->eq('l.isGlobal', ':isPublic'))
                    ->setParameter('isPublic', true, 'boolean');
            }
            $result = $q->getQuery()->getArrayResult();

            $return = [];
            foreach ($result as $r) {
                foreach ($r['leads'] as $l) {
                    $return[$l['lead_id']][$r['id']] = $r;
                }
            }

            return $return;
        } else {
            $q = $this->getEntityManager()->createQueryBuilder()
                ->from('MauticLeadBundle:LeadList', 'l', 'l.id');

            if ($forList) {
                $q->select('partial l.{id, alias, name}, partial il.{lead, list, dateAdded, manuallyAdded, manuallyRemoved}');
            } else {
                $q->select('l');
            }

            $q->leftJoin('l.leads', 'il');

            $q->where(
                $q->expr()->andX(
                    $q->expr()->eq('IDENTITY(il.lead)', (int) $lead),
                    $q->expr()->in('il.manuallyRemoved', ':false')
                )
            )
                ->setParameter('false', false, 'boolean');

            if ($isPublic) {
                $q->andWhere($q->expr()->eq('l.isGlobal', ':isPublic'))
                    ->setParameter('isPublic', true, 'boolean');
            }

            return ($singleArrayHydration) ? $q->getQuery()->getArrayResult() : $q->getQuery()->getResult();
        }
    }

    /**
     * Check Lead segments by ids.
     *
     * @param Lead $lead
     * @param $ids
     *
     * @return bool
     */
    public function checkLeadSegmentsByIds(Lead $lead, $ids)
    {
        if (empty($ids)) {
            return false;
        }

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        $q->join('l', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'x', 'l.id = x.lead_id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->in('x.leadlist_id', $ids),
                    $q->expr()->eq('l.id', ':leadId')
                )
            )
            ->setParameter('leadId', $lead->getId());

        return  (bool) $q->execute()->fetchColumn();
    }

    /**
     * Return a list of global lists.
     *
     * @return array
     */
    public function getGlobalLists()
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->from('MauticLeadBundle:LeadList', 'l', 'l.id');

        $q->select('partial l.{id, name, alias}')
            ->where($q->expr()->eq('l.isPublished', 'true'))
            ->setParameter(':true', true, 'boolean')
            ->andWhere($q->expr()->eq('l.isGlobal', ':true'))
            ->orderBy('l.name');
        if ($this->currentUser != null && !$this->currentUser->isAdmin()) {
            $q->andWhere('l.createdBy != 1');
        }
        $results = $q->getQuery()->getArrayResult();

        return $results;
    }

    /**
     * Get a count of leads that belong to the list.
     *
     * @param $listIds
     *
     * @return array
     */
    public function getLeadCount($listIds, $status = null)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $q->select('count(ll.lead_id) as thecount, ll.leadlist_id')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll')
            ->leftJoin('ll', MAUTIC_TABLE_PREFIX.'leads', 'l', 'll.lead_id = l.id');

        $returnArray = (is_array($listIds));

        if (!$returnArray) {
            $listIds = [$listIds];
        }

        $q->where(
            $q->expr()->in('ll.leadlist_id', $listIds),
            $q->expr()->eq('ll.manually_removed', ':false')
        )
            ->setParameter('false', false, 'boolean')
            ->groupBy('ll.leadlist_id');

        if ($status != null) {
            if ($status == 'Active') {
                $q->where(
                    $q->expr()->in('l.status', ['1', '2'])
                );
            } else {
                $q->where(
                    $q->expr()->in('l.status', ['3', '4', '5', '6'])
                );
            }
        }

        $result = $q->execute()->fetchAll();

        $return = [];
        foreach ($result as $r) {
            $return[$r['leadlist_id']] = $r['thecount'];
        }

        // Ensure lists without leads have a value
        foreach ($listIds as $l) {
            if (!isset($return[$l])) {
                $return[$l] = 0;
            }
        }

        return ($returnArray) ? $return : $return[$listIds[0]];
    }

    public function isAlreadyInserted($leadid=null, $listid=null)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('l.lead_id')
             ->from('lead_lists_leads', 'l')
             ->where($q->expr()->eq('l.lead_id', $leadid),
                 $q->expr()->eq('l.leadlist_id', $listid)
                 );
        $row = $q->execute()->rowCount();
        if ($row) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @param       $lists
     * @param array $args
     *
     * @return array
     */
    public function getLeadsByList($lists, $args = [])
    {
        // Return only IDs
        $idOnly = (!array_key_exists('idOnly', $args)) ? false : $args['idOnly'];
        // Return counts
        $countOnly = (!array_key_exists('countOnly', $args)) ? false : $args['countOnly'];
        // Return only leads that have not been added or manually manipulated to the lists yet
        $newOnly = (!array_key_exists('newOnly', $args)) ? false : $args['newOnly'];
        // Return leads that do not belong to a list based on filters
        $nonMembersOnly = (!array_key_exists('nonMembersOnly', $args)) ? false : $args['nonMembersOnly'];
        // Return only
        $removeAction = (!array_key_exists('removeAction', $args)) ? false : $args['removeAction'];
        // Use filters to dynamically generate the list
        $dynamic = ($newOnly || $nonMembersOnly || (!$newOnly && !$nonMembersOnly && $countOnly));
        // Limiters
        $batchLimiters = (!array_key_exists('batchLimiters', $args)) ? false : $args['batchLimiters'];
        $start         = (!array_key_exists('start', $args)) ? false : $args['start'];
        $limit         = (!array_key_exists('limit', $args)) ? false : $args['limit'];
        $withMinId     = (!array_key_exists('withMinId', $args)) ? false : $args['withMinId'];

        if ((!($lists instanceof PersistentCollection) && !is_array($lists)) || isset($lists['id'])) {
            $lists = [$lists];
        }

        $return = [];
        foreach ($lists as $l) {
            $leads = ($countOnly) ? 0 : [];

            if ($l instanceof LeadList) {
                $id      = $l->getId();
                $filters = $l->getFilters();
            } elseif (is_array($l)) {
                $id      = $l['id'];
                $filters = (!$dynamic) ? [] : $l['filters'];
            } elseif (!$dynamic) {
                $id      = $l;
                $filters = [];
            }

            $parameters    = [];

            if ($dynamic && count($filters)) {
                $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
                if ($countOnly) {
                    $count  = ($this->hasCompanyFilter) ? 'count(distinct(l.id))' : 'count(l.id)';
                    $select = $count.' as lead_count, max(l.id) as max_id';
                    if ($withMinId) {
                        $select .= ', min(l.id) as min_id';
                    }
                } elseif ($idOnly) {
                    $select = 'l.id';
                } else {
                    $select = 'l.*';
                }

                $q->select($select)
                    ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

                $batchExpr = $q->expr()->andX();
                // Only leads that existed at the time of count
                if ($batchLimiters) {
                    if (!empty($batchLimiters['minId']) && !empty($batchLimiters['maxId'])) {
                        $batchExpr->add(
                            $q->expr()->comparison('l.id', 'BETWEEN', "{$batchLimiters['minId']} and {$batchLimiters['maxId']}")
                        );
                    } elseif (!empty($batchLimiters['maxId'])) {
                        $batchExpr->add(
                            $q->expr()->lte('l.id', $batchLimiters['maxId'])
                        );
                    }
                }

                if ($newOnly || !$nonMembersOnly) { // !$nonMembersOnly is mainly used for tests as we just want a live count
                    // foreach ($filters as  $key => $filter) {
                    //$customcreated = true;
                    /** if ($filter['field'] == 'leadlist' && ($filter['operator'] == 'empty' || $filter['operator'] == '!empty')) {
                    unset($q);
                    $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
                    $q->select($select)
                        ->from('leads', 'l')
                        ->leftJoin('l', 'lead_lists_leads', 'll', $q->expr()->eq('l.id', 'll.lead_id'));
                    if ($filter['operator'] == 'empty') {
                        $q->where($q->expr()->isNull('ll.lead_id'));
                    } else {
                        $q->where($q->expr()->isNotNull('ll.lead_id'));
                    }
                    //  select count(l.id) as leads , max(l.id) as max_id from leads l left join lead_lists_leads ll ON l.id=ll.lead_id where ll.lead_id IS NULL;
                    $customcreated = true;
                        } elseif ($filter['field'] == 'tags' && ($filter['operator'] == 'empty' || $filter['operator'] == '!empty')) {
                    unset($q);
                    $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
                    $q->select($select)
                        ->from('leads', 'l')
                        ->leftJoin('l', 'lead_tags_xref', 'll', $q->expr()->eq('l.id', 'll.lead_id'));
                    if ($filter['operator'] == 'empty') {
                        $q->where($q->expr()->isNull('ll.lead_id'));
                    } else {
                        $q->where($q->expr()->isNotNull('ll.lead_id'));
                    }
                    $customcreated = true;
                        } else { */
                    $customcreated = true;
                    $expr          = $this->generateSegmentExpression($filters, $parameters, $q, null, $id);

                    if (!$this->hasCompanyFilter && !$expr->count()) {
                        // Treat this as if it has no filters since all the filters are now invalid (fields were deleted)
                        $return[$id] = [];
                        if ($countOnly) {
                            $return[$id] = [
                                        'count' => 0,
                                        'maxId' => 0,
                                    ];
                            if ($withMinId) {
                                $return[$id]['minId'] = 0;
                            }
                        }

                        continue;
                    }

                    // Leads that do not have any record in the lead_lists_leads table for this lead list
                    // For non null fields - it's apparently better to use left join over not exists due to not using nullable
                    // fields - https://explainextended.com/2009/09/18/not-in-vs-not-exists-vs-left-join-is-null-mysql/
                    $listOnExpr = $q->expr()->andX(
                                $q->expr()->eq('ll.leadlist_id', $id),
                                $q->expr()->eq('ll.lead_id', 'l.id')
                            );

                    if (!empty($batchLimiters['dateTime'])) {
                        // Only leads in the list at the time of count
                        $listOnExpr->add(
                                    $q->expr()->lte('ll.date_added', $q->expr()->literal($batchLimiters['dateTime']))
                                );
                    }

                    $q->leftJoin(
                                'l',
                                MAUTIC_TABLE_PREFIX.'lead_lists_leads',
                                'll',
                                $listOnExpr
                            );

                    if ($newOnly && !$this->checkleadlist) {
                        $expr->add($q->expr()->isNull('ll.lead_id'));
                    }

                    if ($batchExpr->count()) {
                        $expr->add($batchExpr);
                    }

                    if ($expr->count()) {
                        $q->andWhere($expr);
                    }

                    if (!$newOnly) { // live count
                        // Include manually added
                        $q->orWhere(
                                    $q->expr()->eq('ll.manually_added', 1)
                                );

                        $q->andWhere(
                                    $q->expr()->orX(
                                        $q->expr()->isNull('ll.manually_removed'), // account for those not in a list yet
                                        $q->expr()->eq('ll.manually_removed', 0) //exclude manually removed
                                    )
                                );
                    }
                    // }
                  //  }
                } elseif ($nonMembersOnly) {
                    // Only leads that are part of the list that no longer match filters and have not been manually removed
                    $q->join('l', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll', 'l.id = ll.lead_id');

                    $mainExpr = $q->expr()->andX();
                    if ($batchLimiters && !empty($batchLimiters['dateTime'])) {
                        // Only leads in the list at the time of count
                        $mainExpr->add(
                            $q->expr()->lte('ll.date_added', $q->expr()->literal($batchLimiters['dateTime']))
                        );
                    }

                    // Ignore those that have been manually added
                    $mainExpr->addMultiple(
                        [
                            $q->expr()->eq('ll.manually_added', ':false'),
                            $q->expr()->eq('ll.leadlist_id', (int) $id),
                        ]
                    );
                    $q->setParameter('false', false, 'boolean');

                    // Find the contacts that are in the segment but no longer have filters that are applicable
                    $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();
                    $sq->select('l.id')
                        ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

                    $expr = $this->generateSegmentExpression($filters, $parameters, $sq, $q, null, false, '', null, true);

                    if ($expr->count()) {
                        $sq->andWhere($expr);
                    }
                    $mainExpr->add(
                        sprintf('l.id NOT IN (%s)', $sq->getSQL())
                    );

                    if ($batchExpr->count()) {
                        $mainExpr->add($batchExpr);
                    }

                    if (!empty($mainExpr) && $mainExpr->count() > 0) {
                        $q->andWhere($mainExpr);
                    }
                }

                // Set limits if applied
                if (!empty($limit)) {
                    $q->setFirstResult($start)
                        ->setMaxResults($limit);
                }

                if ($countOnly) {
                    // remove any possible group by
                    $q->resetQueryPart('groupBy');
                }

                $results = $q->execute()->fetchAll();

                foreach ($results as $r) {
                    if ($countOnly) {
                        $leads = [
                            'count' => $r['lead_count'],
                            'maxId' => $r['max_id'],
                        ];
                        if ($withMinId) {
                            $leads['minId'] = $r['min_id'];
                        }
                    } elseif ($idOnly) {
                        $leads[$r['id']] = $r['id'];
                    } else {
                        $leads[$r['id']] = $r;
                    }
                }

                if (isset($customcreated)) {
                    $leads['list_id']=$id;
                }
            } elseif (!$dynamic) {
                $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
                if ($countOnly) {
                    $q->select('max(ll.lead_id) as max_id, count(ll.lead_id) as lead_count')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } elseif ($idOnly) {
                    $q->select('ll.lead_id as id')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } else {
                    $q->select('l.*')
                        ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
                        ->join('l', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll', 'l.id = ll.lead_id');
                }

                // Filter by list
                $expr = $q->expr()->andX(
                    $q->expr()->eq('ll.leadlist_id', ':list'),
                    $q->expr()->eq('ll.manually_removed', ':false')
                );

                $q->setParameter('list', (int) $id)
                    ->setParameter('false', false, 'boolean');

                // Set limits if applied
                if (!empty($limit)) {
                    $q->setFirstResult($start)
                        ->setMaxResults($limit);
                }
                if (!empty($expr) && $expr->count() > 0) {
                    $q->where($expr);
                }

                $results = $q->execute()->fetchAll();

                foreach ($results as $r) {
                    if ($countOnly) {
                        $leads = [
                            'count' => $r['lead_count'],
                            'maxId' => $r['max_id'],
                        ];
                    } elseif ($idOnly) {
                        $leads[] = $r['id'];
                    } else {
                        $leads[] = $r;
                    }
                }
            } elseif (count($filters) == 0 && $removeAction) {
                $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
                if ($countOnly) {
                    $q->select('max(ll.lead_id) as max_id, count(ll.lead_id) as lead_count')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } elseif ($idOnly) {
                    $q->select('ll.lead_id as id')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } else {
                    $q->select('l.*')
                        ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
                        ->join('l', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll', 'l.id = ll.lead_id');
                }

                $expr = $q->expr()->andX(
                    $q->expr()->eq('ll.leadlist_id', ':list'),
                    $q->expr()->eq('ll.manually_removed', ':false')
                );

                $q->setParameter('list', (int) $id)
                    ->setParameter('false', false, 'boolean');
                // Set limits if applied
                if (!empty($limit)) {
                    $q->setFirstResult($start)
                        ->setMaxResults($limit);
                }
                if (!empty($expr) && $expr->count() > 0) {
                    $q->where($expr);
                }

                $results = $q->execute()->fetchAll();

                foreach ($results as $r) {
                    if ($countOnly) {
                        $leads = [
                            'count' => $r['lead_count'],
                            'maxId' => $r['max_id'],
                        ];
                    } elseif ($idOnly) {
                        $leads[] = $r['id'];
                    } else {
                        $leads[] = $r;
                    }
                }
            }

            $return[$id] = $leads;

            unset($filters, $parameters, $q, $expr, $results, $dynamicExpr, $leads);
        }

        return $return;
    }

    /**
     * @param array             $filters
     * @param array             $parameters
     * @param QueryBuilder      $q
     * @param QueryBuilder|null $parameterQ
     * @param null              $listId
     * @param bool              $isNot
     * @param string            $leadTablePrefix
     *
     * @return \Doctrine\DBAL\Query\Expression\CompositeExpression|mixed
     */
    public function generateSegmentExpression(array $filters, array &$parameters, QueryBuilder $q, QueryBuilder $parameterQ = null, $listId = null, $isNot = false, $leadTablePrefix = 'l', $leadid=null, $remove=false)
    {
        if (null === $parameterQ) {
            $parameterQ = $q;
        }

        $objectFilters                     = $this->arrangeFilters($filters);
        $this->listFiltersInnerJoinCompany = false;

        if ($this->hasCompanyFilter = isset($objectFilters['company']) && count($objectFilters['company']) > 0) {
            $this->applyCompanyFieldFilters($q, $leadTablePrefix);
        }

        $expr = $this->getListFilterExpr($filters, $parameters, $q, $isNot, $leadid, 'lead', $listId, $remove);

        $paramType = null;
        foreach ($parameters as $k => $v) {
            switch (true) {
                case is_array($v):
                    if (isset($v['type']) && isset($v['value'])) {
                        $paramType = $v['type'];
                        $v         = $v['value'];
                        break;
                    } else {
                        continue;
                    }
                case is_bool($v):
                    $paramType = 'boolean';
                    break;

                case is_int($v):
                    $paramType = 'integer';
                    break;

                case is_float($v):
                    $paramType = 'float';
                    break;

                default:
                    $paramType = null;
                    break;
            }
            $parameterQ->setParameter($k, $v, $paramType);
        }

        return $expr;
    }

    /**
     * This is a public method that can be used by 3rd party.
     * Do not change the signature.
     *
     * @param              $filters
     * @param              $parameters
     * @param QueryBuilder $q
     * @param bool         $isNot
     * @param null         $leadId
     * @param string       $object
     * @param null         $listId
     *
     * @return \Doctrine\DBAL\Query\Expression\CompositeExpression|mixed
     */
    public function getListFilterExpr($filters, &$parameters, QueryBuilder $q, $isNot = false, $leadId = null, $object = 'lead', $listId = null, $remove=false)
    {
        if (!count($filters)) {
            return $q->expr()->andX();
        }

        $schema = $this->getEntityManager()->getConnection()->getSchemaManager();
        // Get table columns
        if (null === $this->leadTableSchema) {
            $this->leadTableSchema = $schema->listTableColumns(MAUTIC_TABLE_PREFIX.'leads');
        }
        if (null === $this->companyTableSchema) {
            $this->companyTableSchema = $schema->listTableColumns(MAUTIC_TABLE_PREFIX.'companies');
        }
        $options = $this->getFilterExpressionFunctions();

        // Add custom filters operators
        if ($this->dispatcher && $this->dispatcher->hasListeners(LeadEvents::LIST_FILTERS_OPERATORS_ON_GENERATE)) {
            $event = new LeadListFiltersOperatorsEvent($options, $this->translator);
            $this->dispatcher->dispatch(LeadEvents::LIST_FILTERS_OPERATORS_ON_GENERATE, $event);
            $options = $event->getOperators();
        }

        $groups    = [];
        $groupExpr = $q->expr()->andX();

        $defaultObject = $object;
        foreach ($filters as $k => $details) {
            $object = $defaultObject;
            if (!empty($details['object'])) {
                $object = $details['object'];
            }
            if (!empty($details['customObject'])) {
                $object = $details['customObject'];
            }
            $column=false;
            if ($details['field'] == 'lead_score') {
                $details['field'] = 'score';
            }
            //if ($details['type'] == 'multiselect') {
            //    $groupExpr = $q->expr()->orX();
            //}
            if ($object == 'lead') {
                $column = isset($this->leadTableSchema[$details['field']]) ? $this->leadTableSchema[$details['field']] : false;
            } elseif ($object == 'company') {
                $column = isset($this->companyTableSchema[$details['field']]) ? $this->companyTableSchema[$details['field']] : false;
            }

            // DBAL does not have a not() function so we have to use the opposite
            $operatorDetails = $options[$details['operator']];
            $func            = $isNot ? $operatorDetails['negate_expr'] : $operatorDetails['expr'];

            if ($object === 'lead') {
                $field = "l.{$details['field']}";
            } elseif ($object === 'company') {
                $field = "comp.{$details['field']}";
            }

            $columnType = false;
            if ($column) {
                // Format the field based on platform specific functions that DBAL doesn't support natively
                $formatter  = AbstractFormatter::createFormatter($this->getEntityManager()->getConnection());
                $columnType = $column->getType();

                switch ($details['type']) {
                    case 'datetime':
                        if (!$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toDateTime($field);
                        }
                        break;
                    case 'date':
                        if (!$columnType instanceof DateType && !$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toDate($field);
                        }
                        break;
                    case 'time':
                        if (!$columnType instanceof TimeType && !$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toTime($field);
                        }
                        break;
                    case 'number':
                        if (!$columnType instanceof IntegerType && !$columnType instanceof FloatType) {
                            $field = $formatter->toNumeric($field);
                        }
                        break;
                }
            }

            //the next one will determine the group
            if ($details['glue'] == 'or') {
                // Create a new group of andX expressions
                if ($groupExpr->count()) {
                    $groups[]  = $groupExpr;
                    $groupExpr = $q->expr()->andX();
                }
            }

            $parameter        = $this->generateRandomParameterName();
            $exprParameter    = ":$parameter";
            $ignoreAutoFilter = false;

            // Special handling of relative date strings
            if ($details['type'] === 'datetime' || $details['type'] === 'date') {
                $relativeDateStrings = $this->getRelativeDateStrings();
                // Check if the column type is a date/time stamp
                $isTimestamp = ($details['type'] === 'datetime' || $columnType instanceof UTCDateTimeType);
                $getDate     = function (&$string) use ($isTimestamp, $relativeDateStrings, &$details, &$func) {
                    $key             = array_search($string, $relativeDateStrings);
                    $dtHelper        = new DateTimeHelper('midnight today', null, 'local');
                    $requiresBetween = in_array($func, ['eq', 'neq']) && $isTimestamp;
                    $timeframe       = str_replace('le.lead.list.', '', $key);
                    $modifier        = false;
                    $isRelative      = true;

                    switch ($timeframe) {
                        case 'birthday':
                        case 'anniversary':
                            $func                = 'like';
                            $isRelative          = false;
                            $details['operator'] = 'like';
                            $details['filter']   = '%'.date('-m-d');
                            break;
                        case 'today':
                        case 'tomorrow':
                        case 'yesterday':
                            if ($timeframe === 'yesterday') {
                                $dtHelper->modify('-1 day');
                            } elseif ($timeframe === 'tomorrow') {
                                $dtHelper->modify('+1 day');
                            }

                            // Today = 2015-08-28 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-08-28 00:00:00
                                //  field < 2015-08-29 00:00:00

                                // neq:
                                // field < 2015-08-28 00:00:00
                                // field >= 2015-08-29 00:00:00
                                $modifier = '+1 day';
                            } else {
                                // lt:
                                //  field < 2015-08-28 00:00:00
                                // gt:
                                //  field > 2015-08-28 23:59:59

                                // lte:
                                //  field <= 2015-08-28 23:59:59
                                // gte:
                                //  field >= 2015-08-28 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 day -1 second';
                                }
                            }
                            break;
                        case 'last_03':
                        case 'last_07':
                        case 'last_15':
                        case 'last_30':
                        case 'last_60':
                        case 'last_90':
                        case 'next_03':
                        case 'next_07':
                        case 'next_15':
                        case 'next_30':
                        case 'next_60':
                        case 'next_90':
                        $requiresBetween = 'true';
                            $daycount    = substr($timeframe, -2);
                            if (strpos($timeframe, 'next') !== false) {
                                //  $dtHelper->modify('+'.$daycount.' day');
                            } else {
                                $dtHelper->modify('-'.$daycount.' day');
                            }

                            if ($requiresBetween) {
                                $modifier = '+'.$daycount.' day';
                            } else {
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+'.$daycount.' day -1 second';
                                }
                            }
                            break;
                        case 'week_last':
                        case 'week_next':
                        case 'week_this':
                        $requiresBetween = 'true';
                            $interval    = str_replace('week_', '', $timeframe);
                            $dtHelper->setDateTime('midnight monday '.$interval.' week', null);
                            if ($interval == 'this') {
                                $requiresBetween = 'true';
                                $isTimestamp     = 'true';
                            }
                            // This week: Monday 2015-08-24 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= Mon 2015-08-24 00:00:00
                                //  field <  Mon 2015-08-31 00:00:00

                                // neq:
                                // field <  Mon 2015-08-24 00:00:00
                                // field >= Mon 2015-08-31 00:00:00
                                $modifier = '+1 week';
                            } else {
                                // lt:
                                //  field < Mon 2015-08-24 00:00:00
                                // gt:
                                //  field > Sun 2015-08-30 23:59:59

                                // lte:
                                //  field <= Sun 2015-08-30 23:59:59
                                // gte:
                                //  field >= Mon 2015-08-24 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 week -1 second';
                                }
                            }
                            break;

                        case 'month_last':
                        case 'month_next':
                        case 'month_this':
                        $requiresBetween = 'true';
                            $interval    = substr($key, -4);
                            $dtHelper->setDateTime('midnight first day of '.$interval.' month', null);
                            if ($interval == 'this') {
                                $requiresBetween = 'true';
                                $isTimestamp     = 'true';
                            }
                            // This month: 2015-08-01 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-08-01 00:00:00
                                //  field <  2015-09:01 00:00:00

                                // neq:
                                // field <  2015-08-01 00:00:00
                                // field >= 2016-09-01 00:00:00
                                $modifier = '+1 month';
                            } else {
                                // lt:
                                //  field < 2015-08-01 00:00:00
                                // gt:
                                //  field > 2015-08-31 23:59:59

                                // lte:
                                //  field <= 2015-08-31 23:59:59
                                // gte:
                                //  field >= 2015-08-01 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 month -1 second';
                                }
                            }
                            break;
                        case 'year_last':
                        case 'year_next':
                        case 'year_this':
                        case 'year_till':
                        $requiresBetween = 'true';
                            $interval    = substr($key, -4);
                            if ($interval == 'till') {
                                $interval = 'this';
                            }
                            $dtHelper->setDateTime('midnight first day of January '.$interval.' year', null);
                                if ($interval == 'this') {
                                    $requiresBetween = 'true';
                                    $isTimestamp     = 'true';
                                }
                            // This year: 2015-01-01 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-01-01 00:00:00
                                //  field <  2016-01-01 00:00:00

                                // neq:
                                // field <  2015-01-01 00:00:00
                                // field >= 2016-01-01 00:00:00
                                $modifier = '+1 year';
                            } else {
                                // lt:
                                //  field < 2015-01-01 00:00:00
                                // gt:
                                //  field > 2015-12-31 23:59:59

                                // lte:
                                //  field <= 2015-12-31 23:59:59
                                // gte:
                                //  field >= 2015-01-01 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 year -1 second';
                                }
                            }
                            break;
                        default:
                            $isRelative = false;
                            break;
                    }

                    // check does this match php date params pattern?
                    if ($timeframe !== 'anniversary' &&
                        (stristr($string[0], '-') || stristr($string[0], '+'))) {
                        $date = new \DateTime('now');
                        $date->modify($string);

                        $dateTime = $date->format('Y-m-d H:i:s');
                        $dtHelper->setDateTime($dateTime, null);

                        $isRelative = true;
                    }

                    // set date into datetime for this specific fields to compare correctly
                    if (in_array($details['field'], ['hit_url_date', 'lead_email_click_date', 'lead_email_read_date']) && !$isRelative) {
                        $isRelative=true;
                        $dtHelper->setDateTime($details['filter']);
                    }

                    if ($isRelative) {
                        if ($requiresBetween) {
                            //this specific date type fields need datetime to work correctly
                            $needTimeStamp = in_array($details['field'], ['hit_url_date', 'lead_email_click_date', 'lead_email_read_date']) ? true : false;
                            $startWith     = ($isTimestamp) || $needTimeStamp ? $dtHelper->toUtcString('Y-m-d H:i:s') : $dtHelper->toUtcString('Y-m-d');
                            if ($timeframe == 'year_till') {
                                $dtHelper->setDateTime('now', 'Y-m-d');
                            } else {
                                $dtHelper->modify($modifier);
                            }

                            $endWith = ($isTimestamp) || $needTimeStamp ? $dtHelper->toUtcString('Y-m-d H:i:s') : $dtHelper->toUtcString('Y-m-d');

                            // Use a between statement
                            $func              = ($func == 'neq') ? 'notBetween' : 'between';
                            $details['filter'] = [$startWith, $endWith];
                        } else {
                            if ($modifier) {
                                $dtHelper->modify($modifier);
                            }
                            //this specific date type fields need datetime to work correctly
                            $needTimeStamp     = in_array($details['field'], ['hit_url_date', 'lead_email_click_date', 'lead_email_read_date']) ? true : false;
                            $details['filter'] = $isTimestamp || $needTimeStamp ? $dtHelper->toUtcString('Y-m-d H:i:s') : $dtHelper->toUtcString('Y-m-d');
                        }
                    }
                };

                if (is_array($details['filter'])) {
                    foreach ($details['filter'] as &$filterValue) {
                        $getDate($filterValue);
                    }
                } else {
                    if (\DateTime::createFromFormat('Y-m-d h:m', $details['filter']) !== false) {
                        $details['filter']= $details['filter'].''.':00';
                    }
                    $getDate($details['filter']);
                }
            }

            // Generate a unique alias
            $alias = $this->generateRandomParameterName();

            switch ($details['field']) {
                case 'hit_url':
                case 'referer':
                case 'source':
                case 'source_id':
                case 'url_title':
                    $operand = in_array(
                        $func,
                        [
                            'eq',
                            'like',
                            'regexp',
                            'notRegexp',
                            'startsWith',
                            'endsWith',
                            'contains',
                        ]
                    ) ? 'EXISTS' : 'NOT EXISTS';

                    $ignoreAutoFilter = true;
                    $column           = $details['field'];

                    if ($column == 'hit_url') {
                        $column = 'url';
                    }

                    if ($column == 'url_title') {
                        $column = 'id';
                    }

                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.'page_hits', $alias);

                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.'.$column, $exprParameter),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'regexp':
                        case 'notRegexp':
                            $parameters[$parameter] = $this->prepareRegex($details['filter']);
                            $not                    = ($func === 'notRegexp') ? ' NOT' : '';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                    $alias.'.'.$column.$not.' REGEXP '.$exprParameter
                                )
                            );
                            break;
                        case 'like':
                        case 'notLike':
                        case 'startsWith':
                        case 'endsWith':
                        case 'contains':
                            switch ($func) {
                                case 'like':
                                case 'notLike':
                                case 'contains':
                                    $parameters[$parameter] = '%'.$details['filter'].'%';
                                    break;
                                case 'startsWith':
                                    $parameters[$parameter] = $details['filter'].'%';
                                    break;
                                case 'endsWith':
                                    $parameters[$parameter] = '%'.$details['filter'];
                                    break;
                            }

                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->like($alias.'.'.$column, $exprParameter),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        default:
                            if ($details['field'] == 'url_title') {
                                $table            = 'page_hits';
                                $column           = 'page_id';
                                $subQueryFilters  =[];
                                $func             = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';
                                $ignoreAutoFilter = true;
                                $specific         = true;
                                $subQb            = $this->createFilterExpressionSubQuery(
                                    $table,
                                    $alias,
                                    $column,
                                    $details['filter'],
                                    $parameters,
                                    $leadId,
                                    $subQueryFilters,
                                    $specific
                                );
                                $groupExpr->add(
                                        sprintf('%s (%s)', $func, $subQb->getSQL())
                                    );

                                break;
                            } else {
                                $parameters[$parameter] = implode(',', $details['filter']);
                                $subqb->where(
                                    $q->expr()->andX(
                                        $q->expr()->$func($alias.'.'.$column, $exprParameter)
                                    )
                                );
                                break;
                            }
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }
                if ($details['field'] != 'url_title') {
                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                }
                    break;
                case 'device_model':
                    $ignoreAutoFilter = true;
                    $operand          = in_array($func, ['eq', 'like', 'regexp', 'notRegexp']) ? 'EXISTS' : 'NOT EXISTS';

                    $column = $details['field'];
                    $subqb  = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_devices', $alias);
                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.'.$column, $exprParameter),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'like':
                        case '!like':
                            $parameters[$parameter] = '%'.$details['filter'].'%';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->like($alias.'.'.$column, $exprParameter),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'regexp':
                        case 'notRegexp':
                            $parameters[$parameter] = $this->prepareRegex($details['filter']);
                            $not                    = ($func === 'notRegexp') ? ' NOT' : '';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                    $alias.'.'.$column.$not.' REGEXP '.$exprParameter
                                )
                            );
                            break;
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }
                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                    break;
                case 'hit_url_date':
                case 'lead_email_click_date':
                case 'lead_email_read_date':
                    $operand = (in_array($func, ['eq', 'gt', 'lt', 'gte', 'lte', 'between'])) ? 'EXISTS' : 'NOT EXISTS';
                    $table   = 'page_hits';
                    $column  = 'date_hit';
                    $expn    = '';
                    if ($details['field'] == 'lead_email_read_date') {
                        $column = 'date_read';
                        $table  = 'email_stats';
                    }
                   if ($details['field'] == 'lead_email_click_date') {
                       $expn= $q->expr()->isNotNull($alias.'.email_id');
                   }
                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter]  = $details['filter'];
                            $parameter2              = $this->generateRandomParameterName();
                            $parameters[$parameter]  = $details['filter'];
                            $dateTimeHelper          = new DateTimeHelper('midnight today', null, 'local');
                            $dateTimeHelper->setDateTime($details['filter']);
                            $dateTimeHelper->modify('+1 day');
                            $dtfilter2               = $dateTimeHelper->getString('Y-m-d H:i:s');
                            $parameters[$parameter2] = $dtfilter2;
                            $exprParameter2          = ":$parameter2";
                            $ignoreAutoFilter        = true;
                            $field                   = $column;
                            $subqb->where(
                                $q->expr()
                                    ->andX(
                                        $q->expr()->gte($alias.'.'.$field, $exprParameter),
                                        $q->expr()->lt($alias.'.'.$field, $exprParameter2),
                                        $q->expr()
                                            ->eq($alias.'.lead_id', 'l.id'),
                                            $expn
                                        )
                                );
                            break;
                        case 'gte':
                        case 'lte':
                            $parameters[$parameter] = $details['filter'];
                            if ($func == 'gte') {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()
                                                ->gte($alias.'.'.$column, $exprParameter),
                                            $q->expr()
                                                ->eq($alias.'.lead_id', 'l.id'),
                                            $expn
                                        )
                                );
                            } else {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()
                                                ->lte($alias.'.'.$column, $exprParameter),
                                            $q->expr()
                                                ->eq($alias.'.lead_id', 'l.id'),
                                            $expn
                                        )
                                );
                            }
                            break;
                        case 'between':
                        case 'notBetween':
                            // Filter should be saved with double || to separate options
                            $parameter2              = $this->generateRandomParameterName();
                            $parameters[$parameter]  = $details['filter'][0];
                            $parameters[$parameter2] = $details['filter'][1];
                            $exprParameter2          = ":$parameter2";
                            $ignoreAutoFilter        = true;
                            $field                   = $column;

                            if ($func == 'between') {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()->gte($alias.'.'.$field, $exprParameter),
                                            $q->expr()->lt($alias.'.'.$field, $exprParameter2),
                                            $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                            $expn
                                        )
                                );
                            } else {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()->lt($alias.'.'.$field, $exprParameter),
                                            $q->expr()->gte($alias.'.'.$field, $exprParameter2),
                                            $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                            $expn
                                        )
                                );
                            }
                            break;
                        default:
                            $parameters[$parameter] = $details['filter'];

                            $subqb->where(
                                $q->expr()
                                    ->andX(
                                        $q->expr()
                                            ->$func(
                                                $alias.'.'.$column,
                                                $exprParameter
                                            ),
                                        $q->expr()
                                            ->eq($alias.'.lead_id', 'l.id'),
                                        $expn
                                    )
                            );
                            break;
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId),
                            $expn
                        );
                    }

                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                    break;
                case 'page_id':
                case 'email_id':
                case 'redirect_id':
                case 'notification':
                    $operand = ($func == 'eq') ? 'EXISTS' : 'NOT EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'id';

                    if ($details['field'] == 'notification') {
                        $table  = 'push_ids';
                        $column = 'id';
                    }

                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    if ($details['filter'] == 1) {
                        $subqb->where(
                            $q->expr()
                                ->andX(
                                    $q->expr()
                                        ->isNotNull($alias.'.'.$column),
                                    $q->expr()
                                        ->eq($alias.'.lead_id', 'l.id')
                                )
                        );
                    } else {
                        $subqb->where(
                            $q->expr()
                                ->andX(
                                    $q->expr()
                                        ->isNull($alias.'.'.$column),
                                    $q->expr()
                                        ->eq($alias.'.lead_id', 'l.id')
                                )
                        );
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }

                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                    break;
                case 'sessions':
                    $operand = 'EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'COUNT(id)';
                    $subqb   = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    $alias2 = $this->generateRandomParameterName();
                    $subqb2 = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($alias2.'.id')
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias2);

                    $subqb2->where(
                        $q->expr()
                            ->andX(
                                $q->expr()->eq($alias2.'.lead_id', 'l.id'),
                                $q->expr()->gt($alias2.'.date_hit', '('.$alias.'.date_hit - INTERVAL 30 MINUTE)'),
                                $q->expr()->lt($alias2.'.date_hit', $alias.'.date_hit')
                            )
                    );

                    $parameters[$parameter] = $details['filter'];

                    $subqb->where(
                        $q->expr()
                            ->andX(
                                $q->expr()
                                    ->eq($alias.'.lead_id', 'l.id'),
                                $q->expr()
                                    ->isNull($alias.'.email_id'),
                                $q->expr()
                                    ->isNull($alias.'.redirect_id'),
                                sprintf('%s (%s)', 'NOT EXISTS', $subqb2->getSQL())
                            )
                    );

                    $opr = '';
                    switch ($func) {
                        case 'eq':
                            $opr = '=';
                            break;
                        case 'gt':
                            $opr = '>';
                            break;
                        case 'gte':
                            $opr = '>=';
                            break;
                        case 'lt':
                            $opr = '<';
                            break;
                        case 'lte':
                            $opr = '<=';
                            break;
                    }
                    if ($opr) {
                        $parameters[$parameter] = $details['filter'];
                        $subqb->having($select.$opr.$details['filter']);
                    }
                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                    break;
                case 'hit_url_count':
                case 'lead_email_click_count':
                case 'lead_email_read_count':
                    $operand = 'EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'COUNT(id)';
                    $expn    = '';
                if ($details['field'] == 'lead_email_read_count') {
                    $table  = 'email_stats';
                    $select = 'COALESCE(SUM(open_count),0)';
                }
                if ($details['field'] == 'lead_email_click_count') {
                    $expn= $q->expr()->isNotNull($alias.'.email_id');
                }
                $subqb = $this->getEntityManager()->getConnection()
                    ->createQueryBuilder()
                    ->select($select)
                    ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                $parameters[$parameter] = $details['filter'];
                $subqb->where(
                    $q->expr()
                        ->andX(
                            $q->expr()
                                ->eq($alias.'.lead_id', 'l.id'),
                            $expn
                        )
                );

                    $opr = '';
                    switch ($func) {
                        case 'eq':
                            $opr = '=';
                            break;
                        case 'gt':
                            $opr = '>';
                            break;
                        case 'gte':
                            $opr = '>=';
                            break;
                        case 'lt':
                            $opr = '<';
                            break;
                        case 'lte':
                            $opr = '<=';
                            break;
                    }

                    if ($opr) {
                        $parameters[$parameter] = $details['filter'];
                        $subqb->having($select.$opr.$details['filter']);
                    }

                    $groupExpr->add(sprintf('%s (%s)', $operand, $subqb->getSQL()));
                    break;

                case 'dnc_bounced':
                case 'dnc_unsubscribed':
                case 'dnc_bounced_sms':
                case 'dnc_unsubscribed_sms':
                    // Special handling of do not contact
                    $func = (($func == 'eq' && $details['filter']) || ($func == 'neq' && !$details['filter'])) ? 'EXISTS' : 'NOT EXISTS';

                    $parts   = explode('_', $details['field']);
                    $channel = 'email';

                    if (count($parts) === 3) {
                        $channel = $parts[2];
                    }

                    $channelParameter = $this->generateRandomParameterName();
                    $subqb            = $this->getEntityManager()->getConnection()->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', $alias)
                        ->where(
                            $q->expr()->andX(
                                $q->expr()->eq($alias.'.reason', $exprParameter),
                                $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                $q->expr()->eq($alias.'.channel', ":$channelParameter")
                            )
                        );

                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()->eq($alias.'.lead_id', $leadId)
                        );
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $func, $subqb->getSQL())
                    );

                    // Filter will always be true and differentiated via EXISTS/NOT EXISTS
                    $details['filter'] = true;

                    $ignoreAutoFilter = true;

                    $parameters[$parameter]        = ($parts[1] === 'bounced') ? DoNotContact::BOUNCED : DoNotContact::UNSUBSCRIBED;
                    $parameters[$channelParameter] = $channel;

                    break;

                case 'leadlist':
                    if ($details['operator'] == 'empty' || $details['operator'] == '!empty') {
                        $this->checkleadlist=true;
                        $ignoreAutoFilter   = true;
                        $func               = in_array($func, ['notEmpty']) ? 'IN' : 'NOT IN';
                        if ($remove && $details['operator'] == 'empty') {
                            $func = 'IN';
                        }
                        $noval = [];
                        $alias = $this->generateRandomParameterName();
                        $subQb = $this->createFilterExpressionSubQuery('lead_lists_leads', $alias, 'lead_id', null, $noval, null, [], true);
                        $groupExpr->add(
                            sprintf('%s (%s)', 'l.id '.$func, $subQb->getSQL())
                        );
                    } else {
                        $table                       = 'lead_lists_leads';
                        $column                      = 'leadlist_id';
                        $falseParameter              = $this->generateRandomParameterName();
                        $parameters[$falseParameter] = false;
                        $trueParameter               = $this->generateRandomParameterName();
                        $parameters[$trueParameter]  = true;
                        $func                        = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';
                        $ignoreAutoFilter            = true;

                        if ($filterListIds = (array) $details['filter']) {
                            $listQb = $this->getEntityManager()->getConnection()->createQueryBuilder()
                                ->select('l.id, l.filters')
                                ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'l');
                            $listQb->where(
                                $listQb->expr()->in('l.id', $filterListIds)
                            );
                            $filterLists = $listQb->execute()->fetchAll();
                            $not         = 'NOT EXISTS' === $func;

                            // Each segment's filters must be appended as ORs so that each list is evaluated individually
                            $existsExpr = ($not) ? $listQb->expr()->andX() : $listQb->expr()->orX();

                            foreach ($filterLists as $list) {
                                $alias = $this->generateRandomParameterName();
                                $id    = (int) $list['id'];
                                if ($id === (int) $listId) {
                                    // Ignore as somehow self is included in the list
                                    continue;
                                }

                                $listFilters = unserialize($list['filters']);
                                if (empty($listFilters)) {
                                    // Use an EXISTS/NOT EXISTS on contact membership as this is a manual list
                                    $subQb = $this->createFilterExpressionSubQuery(
                                        $table,
                                        $alias,
                                        $column,
                                        $id,
                                        $parameters,
                                        $leadId,
                                        [
                                            $alias.'.manually_removed' => $falseParameter,
                                        ]
                                    );
                                } else {
                                    // Build a EXISTS/NOT EXISTS using the filters for this list to include/exclude those not processed yet
                                    // but also leverage the current membership to take into account those manually added or removed from the segment

                                    // Build a "live" query based on current filters to catch those that have not been processed yet
                                    $subQb      = $this->createFilterExpressionSubQuery('leads', $alias, null, null, $parameters, $leadId);
                                    $filterExpr = $this->generateSegmentExpression($listFilters, $parameters, $subQb, null, $id, false, $alias);

                                    // Left join membership to account for manually added and removed
                                    $membershipAlias = $this->generateRandomParameterName();
                                    $subQb->leftJoin(
                                        $alias,
                                        MAUTIC_TABLE_PREFIX.$table,
                                        $membershipAlias,
                                        "$membershipAlias.lead_id = $alias.id AND $membershipAlias.leadlist_id = $id"
                                    )
                                        ->where(
                                            $subQb->expr()->orX(
                                                $filterExpr,
                                                $subQb->expr()->eq("$membershipAlias.manually_added", ":$trueParameter") //include manually added
                                            )
                                        )
                                        ->andWhere(
                                            $subQb->expr()->eq("$alias.id", 'l.id'),
                                            $subQb->expr()->orX(
                                                $subQb->expr()->isNull("$membershipAlias.manually_removed"), // account for those not in a list yet
                                                $subQb->expr()->eq("$membershipAlias.manually_removed", ":$falseParameter") //exclude manually removed
                                            )
                                        );
                                }

                                $existsExpr->add(
                                    sprintf('%s (%s)', $func, $subQb->getSQL())
                                );
                            }

                            if ($existsExpr->count()) {
                                $groupExpr->add($existsExpr);
                            }
                        }
                    }
                    break;
                case 'listoptin':
                    if ($details['operator'] == 'empty' || $details['operator'] == '!empty') {
                        $this->checkleadlist=true;
                        $ignoreAutoFilter   = true;
                        $func               = in_array($func, ['notEmpty']) ? 'IN' : 'NOT IN';
                        /*if ($remove && $details['operator'] == 'empty') {
                            $func = 'IN';
                        }*/
                        $noval = [];
                        $alias = $this->generateRandomParameterName();
                        $subQb = $this->createFilterExpressionSubQuery('lead_listoptin_leads', $alias, 'lead_id', null, $noval, null, [], true);
                        $groupExpr->add(
                            sprintf('%s (%s)', 'l.id '.$func, $subQb->getSQL())
                        );
                    } else {
                        $table                       = 'lead_listoptin_leads';
                        $column                      = 'leadlist_id';
                        $falseParameter              = $this->generateRandomParameterName();
                        $parameters[$falseParameter] = false;
                        $trueParameter               = $this->generateRandomParameterName();
                        $parameters[$trueParameter]  = true;
                        $func                        = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';
                        $ignoreAutoFilter            = true;
                        //$cuslead                     = $leadId == null ? '' : $leadId;
                        if ($filterListIds = (array) $details['filter']) {
                            $listQb = $this->getEntityManager()->getConnection()->createQueryBuilder()
                                ->select('l.id')
                                ->from(MAUTIC_TABLE_PREFIX.'lead_listoptin', 'l');
                            $listQb->where(
                                $listQb->expr()->in('l.id', $filterListIds)
                            );
                            $filterLists = $listQb->execute()->fetchAll();
                            $not         = 'NOT EXISTS' === $func;

                            // Each segment's filters must be appended as ORs so that each list is evaluated individually
                            $existsExpr = ($not) ? $listQb->expr()->andX() : $listQb->expr()->orX();

                            foreach ($filterLists as $list) {
                                $alias = $this->generateRandomParameterName();
                                $id    = (int) $list['id'];
                                if ($id === (int) $listId) {
                                    // Ignore as somehow self is included in the list
                                    continue;
                                }

                                //$listFilters = unserialize($list['filters']);
                                //if (empty($listFilters)) {
                                // Use an EXISTS/NOT EXISTS on contact membership as this is a manual list
                                $subQb = $this->createFilterExpressionSubQuery(
                                    $table,
                                    $alias,
                                    $column,
                                    $id,
                                    $parameters,
                                    $leadId,
                                    [
                                        $alias.'.manually_removed' => $falseParameter,
                                        $alias.'.confirmed_lead'   => $trueParameter,
                                    ]
                                );
                                /*} else {
                                    // Build a EXISTS/NOT EXISTS using the filters for this list to include/exclude those not processed yet
                                    // but also leverage the current membership to take into account those manually added or removed from the segment

                                    // Build a "live" query based on current filters to catch those that have not been processed yet
                                    $subQb      = $this->createFilterExpressionSubQuery('leads', $alias, null, null, $parameters, $leadId);
                                    $filterExpr = $this->generateSegmentExpression($listFilters, $parameters, $subQb, null, $id, false, $alias);

                                    // Left join membership to account for manually added and removed
                                    $membershipAlias = $this->generateRandomParameterName();
                                    $subQb->leftJoin(
                                        $alias,
                                        MAUTIC_TABLE_PREFIX.$table,
                                        $membershipAlias,
                                        "$membershipAlias.lead_id = $alias.id AND $membershipAlias.leadlist_id = $id"
                                    )
                                        ->where(
                                            $subQb->expr()->orX(
                                                $filterExpr,
                                                $subQb->expr()->eq("$membershipAlias.manually_added", ":$trueParameter") //include manually added
                                            )
                                        )
                                        ->andwhere(
                                            $subQb->expr()->orX(
                                                $subQb->expr()->eq("$membershipAlias.confirmed_lead", ":$trueParameter") //include confirmed leads
                                            )
                                        )
                                        ->andWhere(
                                            $subQb->expr()->eq("$alias.id", 'l.id'),
                                            $subQb->expr()->orX(
                                                $subQb->expr()->isNull("$membershipAlias.manually_removed"), // account for those not in a list yet
                                                $subQb->expr()->eq("$membershipAlias.manually_removed", ":$falseParameter") //exclude manually removed
                                            )
                                        );
                                }*/

                                $existsExpr->add(
                                    sprintf('%s (%s)', $func, $subQb->getSQL())
                                );
                            }

                            if ($existsExpr->count()) {
                                $groupExpr->add($existsExpr);
                            }
                        }
                    }
                    break;
                case 'lead_email_activity':
                    $func                    = ($func == 'in') ? 1 : 0;
                    $select                  = 'id';
                    $table                   = 'email_stats';
                    $isexist                 = 'EXISTS';
                    $ignoreAutoFilter        = true;
                    $parameters[$parameter]  = $func;
                    $subqb                   = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    $subqb->where(
                        $q->expr()
                            ->andX(
                                $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                $q->expr()->eq($alias.'.is_read', $exprParameter),
                                $q->expr()->isNotNull($alias.'.email_id')
                            )
                    );
                    $subqb->orderBy($alias.'.id', 'DESC')->setMaxResults($details['filter']);
                    $groupExpr->add(
                        sprintf('%s (%s)', $isexist, $subqb->getSQL())
                    );
                    break;
                case 'tags':
                case 'globalcategory':
                case 'lead_email_received':
                case 'drip_email_read':
                case 'lead_email_sent':
                case 'lead_email_failed':
                case 'device_type':
                case 'device_brand':
                case 'device_os':
                case 'lead_form_submit':
                case 'asset_downloads':
                case 'lead_email_click':
                case 'drip_email_click':
                case 'drip_email_subscribed':
                case 'drip_email_completed':
                case 'drip_email_sent':
                case 'drip_email_failed':
                    // Special handling of lead lists and tags
                    $func = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';

                    $ignoreAutoFilter = true;

                    // Collect these and apply after building the query because we'll want to apply the lead first for each of the subqueries
                    $subQueryFilters = [];
                    switch ($details['field']) {
                        case 'tags':
                            $table  = 'lead_tags_xref';
                            $column = 'tag_id';
                            break;
                        case 'globalcategory':
                            $table  = 'lead_categories';
                            $column = 'category_id';
                            break;
                        case 'lead_email_received':
                        case 'drip_email_read':
                            $table  = 'email_stats';
                            $column = 'email_id';

                            $trueParameter                      = $this->generateRandomParameterName();
                            $subQueryFilters[$alias.'.is_read'] = $trueParameter;
                            $parameters[$trueParameter]         = true;
                            break;
                        /*case 'owner_id':
                            $table  = 'users';
                            $column = 'id';
                            $alias  = 'll';
                            break;*/
                        case 'lead_email_sent':
                        case 'drip_email_sent':
                        case 'lead_email_failed':
                        case 'drip_email_failed':
                            $table  = 'email_stats';
                            $column = 'email_id';
                            break;
                        case 'device_type':
                            $table  = 'lead_devices';
                            $column = 'device';
                            break;
                        case 'device_brand':
                            $table  = 'lead_devices';
                            $column = 'device_brand';
                            break;
                        case 'device_os':
                            $table  = 'lead_devices';
                            $column = 'device_os_name';
                            break;
                        case 'lead_form_submit':
                            $table  = 'form_submissions';
                            $column = 'form_id';
                            break;
                        case 'asset_downloads':
                            $table  = 'asset_downloads';
                            $column = 'asset_id';
                            break;
                        case 'lead_email_click':
                        case 'drip_email_click':
                            $table  = 'page_hits';
                            $column = 'email_id';
                            break;
                        case 'drip_email_subscribed':
                            $table  = 'dripemail_leads';
                            $column = 'dripemail_id';
                            break;
                        case 'drip_email_completed':
                            $table  = 'dripemail_lead_event_log';
                            $column = 'dripemail_id';
                            break;
                    }

                if ($details['field'] == 'tags' && ($details['operator'] == 'empty' || $details['operator'] == '!empty')) {
                    $this->checkleadlist=true;
                    $ignoreAutoFilter   = true;
                    $func               = $details['operator'] == '!empty' ? 'IN' : 'NOT IN';
                    $noval              = [];
                    $alias              = $this->generateRandomParameterName();
                    $subQb              = $this->createFilterExpressionSubQuery('lead_tags_xref', $alias, 'lead_id', null, $noval, null, [], true);
                    $groupExpr->add(
                        sprintf('%s (%s)', 'l.id '.$func, $subQb->getSQL())
                    );
                } else {
                    $subQb = $this->createFilterExpressionSubQuery(
                        $table,
                        $alias,
                        $column,
                        $details['filter'],
                        $parameters,
                        $leadId,
                        $subQueryFilters
                    );

                    if ($details['field'] == 'drip_email_completed') {
                        $column= $alias.'.rotation';
                        $subQb->andWhere($column.' = "1"');
                    }
                    if ($details['field'] == 'lead_email_failed' || $details['field'] == 'drip_email_failed') {
                        $column= $alias.'.is_failed';
                        $subQb->andWhere($column.' = "1"');
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $func, $subQb->getSQL())
                    );
                }
                    break;
                case 'owner_id':
                    $subQueryFilters = [];
                    $table           = 'users';
                    $column          = 'id';
                    $alias           = 'u';

                    if ($details['field'] == 'owner_id' && ($func != 'empty' && $func != '!empty')) {
                        $subQb = $this->createFilterExpressionSubQuery(
                            $table,
                            $alias,
                            $column,
                            $details['filter'],
                            $parameters,
                            $leadId,
                            $subQueryFilters
                        );
                        $func = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';
                        $groupExpr->add(
                            sprintf('%s (%s)', $func, $subQb->getSQL())
                        );
                    } else {
                        switch ($func) {
                            case 'empty':
                                $groupExpr->add(
                                    $q->expr()->isNull('l.owner_id')
                                );
                                break;
                            case 'notEmpty':
                                $groupExpr->add(
                                    $q->expr()->isNotNull('l.owner_id')
                                );
                                break;
                        }
                    }
                case 'stage':
                    // A note here that SQL EXISTS is being used for the eq and neq cases.
                    // I think this code might be inefficient since the sub-query is rerun
                    // for every row in the outer query's table. This might have to be refactored later on
                    // if performance is desired.

                    $subQb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'stages', $alias);

                    switch ($func) {
                        case 'empty':
                            $groupExpr->add(
                                $q->expr()->isNull('l.stage_id')
                            );
                            break;
                        case 'notEmpty':
                            $groupExpr->add(
                                $q->expr()->isNotNull('l.stage_id')
                            );
                            break;
                        case 'eq':
                            $parameters[$parameter] = $details['filter'];

                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.id', 'l.stage_id'),
                                    $q->expr()->eq($alias.'.id', ":$parameter")
                                )
                            );
                            $groupExpr->add(sprintf('EXISTS (%s)', $subQb->getSQL()));
                            break;
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];

                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.id', 'l.stage_id'),
                                    $q->expr()->eq($alias.'.id', ":$parameter")
                                )
                            );
                            $groupExpr->add(sprintf('NOT EXISTS (%s)', $subQb->getSQL()));
                            break;
                    }

                    break;
                case 'integration_campaigns':
                    $parameter2       = $this->generateRandomParameterName();
                    $operand          = in_array($func, ['eq', 'neq']) ? 'EXISTS' : 'NOT EXISTS';
                    $ignoreAutoFilter = true;

                    $subQb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'integration_entity', $alias);
                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            if (strpos($details['filter'], '::') !== false) {
                                list($integrationName, $campaignId) = explode('::', $details['filter']);
                            } else {
                                // Assuming this is a Salesforce integration for BC with pre 2.11.0
                                $integrationName = 'Salesforce';
                                $campaignId      = $details['filter'];
                            }

                            $parameters[$parameter]  = $campaignId;
                            $parameters[$parameter2] = $integrationName;
                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.integration', ":$parameter2"),
                                    $q->expr()->eq($alias.'.integration_entity', "'CampaignMember'"),
                                    $q->expr()->eq($alias.'.integration_entity_id', ":$parameter"),
                                    $q->expr()->eq($alias.'.internal_entity', "'lead'"),
                                    $q->expr()->eq($alias.'.internal_entity_id', 'l.id')
                                )
                            );
                            break;
                    }

                    $groupExpr->add(sprintf('%s (%s)', $operand, $subQb->getSQL()));

                    break;
                default:
                    if (!$column) {
                        // Column no longer exists so continue
                        continue;
                    }

                    if ('company' === $object) {
                        // Must tell getLeadsByList how to best handle the relationship with the companies table
                        if (!in_array($func, ['empty', 'neq', 'notIn', 'notLike'])) {
                            $this->listFiltersInnerJoinCompany = true;
                        }
                    }

                    switch ($func) {
                        case 'between':
                        case 'notBetween':
                            // Filter should be saved with double || to separate options
                            $parameter2              = $this->generateRandomParameterName();
                            $parameters[$parameter]  = $details['filter'][0];
                            $parameters[$parameter2] = $details['filter'][1];
                            $exprParameter2          = ":$parameter2";
                            $ignoreAutoFilter        = true;

                            if ($func == 'between') {
                                $groupExpr->add(
                                    $q->expr()->andX(
                                        $q->expr()->gte($field, $exprParameter),
                                        $q->expr()->lt($field, $exprParameter2)
                                    )
                                );
                            } else {
                                $groupExpr->add(
                                    $q->expr()->andX(
                                        $q->expr()->lt($field, $exprParameter),
                                        $q->expr()->gte($field, $exprParameter2)
                                    )
                                );
                            }
                            break;

                        case 'notEmpty':
                            $groupExpr->add(
                                $q->expr()->andX(
                                    $q->expr()->isNotNull($field),
                                    $q->expr()->neq($field, $q->expr()->literal(''))
                                )
                            );
                            $ignoreAutoFilter = true;
                            break;

                        case 'empty':
                            $details['filter'] = '';
                            $groupExpr->add(
                                $this->generateFilterExpression($q, $field, 'eq', $exprParameter, true)
                            );
                            break;

                        case 'in':
                        case 'notIn':
                            foreach ($details['filter'] as &$value) {
                                $value = $q->expr()->literal(
                                    InputHelper::clean($value)
                                );
                            }
                            if ($details['type'] == 'multiselect') {
                                foreach ($details['filter'] as $filter) {
                                    $filter = trim($filter, "'");

                                    if (substr($func, 0, 3) === 'not') {
                                        $operator = 'NOT REGEXP';
                                    } else {
                                        $operator = 'REGEXP';
                                    }

                                    $groupExpr->add(
                                        $field." $operator '\\\\|?$filter\\\\|?'"
                                    );
                                }
                            } else {
                                $groupExpr->add(
                                    $this->generateFilterExpression($q, $field, $func, $details['filter'], null)
                                );
                            }
                            $ignoreAutoFilter = true;
                            break;

                        case 'neq':
                            $groupExpr->add(
                                $this->generateFilterExpression($q, $field, $func, $exprParameter, null)
                            );
                            break;

                        case 'like':
                        case 'notLike':
                        case 'startsWith':
                        case 'endsWith':
                        case 'contains':
                            $ignoreAutoFilter = true;

                            switch ($func) {
                                case 'like':
                                case 'notLike':
                                    $parameters[$parameter] = (strpos($details['filter'], '%') === false) ? '%'.$details['filter'].'%'
                                        : $details['filter'];
                                    break;
                                case 'startsWith':
                                    $func                   = 'like';
                                    $parameters[$parameter] = $details['filter'].'%';
                                    break;
                                case 'endsWith':
                                    $func                   = 'like';
                                    $parameters[$parameter] = '%'.$details['filter'];
                                    break;
                                case 'contains':
                                    $func                   = 'like';
                                    $parameters[$parameter] = '%'.$details['filter'].'%';
                                    break;
                            }

                            $groupExpr->add(
                                $this->generateFilterExpression($q, $field, $func, $exprParameter, null)
                            );
                            break;
                        case 'regexp':
                        case 'notRegexp':
                            $ignoreAutoFilter       = true;
                            $parameters[$parameter] = $this->prepareRegex($details['filter']);
                            $not                    = ($func === 'notRegexp') ? ' NOT' : '';
                            $groupExpr->add(
                            // Escape single quotes while accounting for those that may already be escaped
                                $field.$not.' REGEXP '.$exprParameter
                            );
                            break;
                        default:
                            $groupExpr->add($q->expr()->$func($field, $exprParameter));
                    }
            }

            if (!$ignoreAutoFilter) {
                if (!is_array($details['filter'])) {
                    switch ($details['type']) {
                        case 'number':
                            $details['filter'] = (float) $details['filter'];
                            break;

                        case 'boolean':
                            $details['filter'] = (bool) $details['filter'];
                            break;
                    }
                }

                $parameters[$parameter] = $details['filter'];
            }

            if ($this->dispatcher && $this->dispatcher->hasListeners(LeadEvents::LIST_FILTERS_ON_FILTERING)) {
                $event = new LeadListFilteringEvent($details, $leadId, $alias, $func, $q, $this->getEntityManager());
                $this->dispatcher->dispatch(LeadEvents::LIST_FILTERS_ON_FILTERING, $event);
                if ($event->isFilteringDone()) {
                    $groupExpr->add($event->getSubQuery());
                }
            }
        }

        // Get the last of the filters
        if ($groupExpr->count()) {
            $groups[] = $groupExpr;
        }
        if (count($groups) === 1) {
            // Only one andX expression
            $expr = $groups[0];
        } elseif (count($groups) > 1) {
            // Sets of expressions grouped by OR
            $orX = $q->expr()->orX();
            $orX->addMultiple($groups);

            // Wrap in a andX for other functions to append
            $expr = $q->expr()->andX($orX);
        } else {
            $expr = $groupExpr;
        }

        return $expr;
    }

    /**
     * @param $filters
     *
     * @return array
     */
    public function arrangeFilters($filters)
    {
        $objectFilters = [];
        if (empty($filters)) {
            $objectFilters['lead'][] = $filters;
        }
        foreach ($filters as $filter) {
            $object = (isset($filter['object'])) ? $filter['object'] : 'lead';
            switch ($object) {
                case 'company':
                    $objectFilters['company'][] = $filter;
                    break;
                default:
                    $objectFilters['lead'][] = $filter;
                    break;
            }
        }

        return $objectFilters;
    }

    /**
     * @param EventDispatcherInterface $dispatcher
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param       $table
     * @param       $alias
     * @param       $column
     * @param       $value
     * @param array $parameters
     * @param null  $leadId
     * @param array $subQueryFilters
     *
     * @return QueryBuilder
     */
    protected function createFilterExpressionSubQuery($table, $alias, $column, $value, array &$parameters = [], $leadId = null, array $subQueryFilters = [], $custom = false)
    {
        $subQb   = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $subExpr = $subQb->expr()->andX();

        if ('leads' !== $table && 'users' !== $table && !$custom) {
            $subExpr->add(
                $subQb->expr()->eq($alias.'.lead_id', 'l.id')
            );
        }

        if ('users' == $table) {
            $subExpr->add(
                $subQb->expr()->eq($alias.'.id', 'l.owner_id')
            );
        }

        // Specific lead
        if (!empty($leadId)) {
            if ('users' !== $table) {
                $columnName = ('leads' === $table) ? 'id' : 'lead_id';
                $subExpr->add(
                    $subQb->expr()->eq($alias.'.'.$columnName, $leadId)
                );
            } else {
                $columnName = ('users' === $table) ? 'id' : 'lead_id';
                $subExpr->add(
                    $subQb->expr()->eq('l.'.$columnName, $leadId)
                );
            }
        }

        foreach ($subQueryFilters as $subColumn => $subParameter) {
            $subExpr->add(
                $subQb->expr()->eq($subColumn, ":$subParameter")
            );
        }

        if (null !== $value && !empty($column)) {
            $subFilterParamter = $this->generateRandomParameterName();
            $subFunc           = 'eq';
            if (is_array($value)) {
                $subFunc = 'in';
                $subExpr->add(
                    $subQb->expr()->in(sprintf('%s.%s', $alias, $column), ":$subFilterParamter")
                );
                $parameters[$subFilterParamter] = ['value' => $value, 'type' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY];
            } else {
                $parameters[$subFilterParamter] = $value;
            }

            $subExpr->add(
                $subQb->expr()->$subFunc(sprintf('%s.%s', $alias, $column), ":$subFilterParamter")
            );
        }
        if (!$custom) {
            $subQb->select('null')
                ->from(MAUTIC_TABLE_PREFIX.$table, $alias)
                ->where($subExpr);
        } else {
            $subQb->select($alias.'.'.$column)
                ->from(MAUTIC_TABLE_PREFIX.$table, $alias);
        }

        return $subQb;
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addCatchAllWhereClause($q, $filter)
    {
        return $this->addStandardCatchAllWhereClause(
            $q,
            $filter,
            [
                'l.name',
                'l.alias',
            ]
        );
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addSearchCommandWhereClause($q, $filter)
    {
        list($expr, $parameters) = parent::addSearchCommandWhereClause($q, $filter);
        if ($expr) {
            return [$expr, $parameters];
        }

        $command         = $filter->command;
        $unique          = $this->generateRandomParameterName();
        $returnParameter = false; //returning a parameter that is not used will lead to a Doctrine error

        switch ($command) {
            case $this->translator->trans('mautic.core.searchcommand.ismine'):
            case $this->translator->trans('mautic.core.searchcommand.ismine', [], null, 'en_US'):
                $expr = $q->expr()->eq('l.createdBy', $this->currentUser->getId());
                break;
            case $this->translator->trans('le.lead.list.searchcommand.isglobal'):
            case $this->translator->trans('le.lead.list.searchcommand.isglobal', [], null, 'en_US'):
                $expr            = $q->expr()->eq('l.isGlobal', ":$unique");
                $forceParameters = [$unique => true];
                break;
            case $this->translator->trans('mautic.core.searchcommand.ispublished'):
            case $this->translator->trans('mautic.core.searchcommand.ispublished', [], null, 'en_US'):
                $expr            = $q->expr()->eq('l.isPublished', ":$unique");
                $forceParameters = [$unique => true];
                break;
            case $this->translator->trans('mautic.core.searchcommand.isunpublished'):
            case $this->translator->trans('mautic.core.searchcommand.isunpublished', [], null, 'en_US'):
                $expr            = $q->expr()->eq('l.isPublished', ":$unique");
                $forceParameters = [$unique => false];
                break;
            case $this->translator->trans('mautic.core.searchcommand.name'):
            case $this->translator->trans('mautic.core.searchcommand.name', [], null, 'en_US'):
                $expr            = $q->expr()->like('l.name', ':'.$unique);
                $returnParameter = true;
                break;
        }

        if (!empty($forceParameters)) {
            $parameters = $forceParameters;
        } elseif ($returnParameter) {
            $string     = ($filter->strict) ? $filter->string : "%{$filter->string}%";
            $parameters = ["$unique" => $string];
        }

        return [
            $expr,
            $parameters,
        ];
    }

    /**
     * @return array
     */
    public function getSearchCommands()
    {
        $commands = [
            'le.lead.list.searchcommand.isglobal',
            'mautic.core.searchcommand.ismine',
            'mautic.core.searchcommand.ispublished',
            'mautic.core.searchcommand.isinactive',
            'mautic.core.searchcommand.name',
        ];

        return array_merge($commands, parent::getSearchCommands());
    }

    /**
     * @return array
     */
    public function getRelativeDateStrings()
    {
        $keys = self::getRelativeDateTranslationKeys();

        $strings = [];
        foreach ($keys as $key) {
            if ($this->translator == null) {
                continue;
            }
            $strings[$key] = $this->translator->trans($key);
        }

        return $strings;
    }

    /**
     * @return array
     */
    public static function getRelativeDateTranslationKeys()
    {
        return [
            'le.lead.list.month_last',
            'le.lead.list.month_next',
            'le.lead.list.month_this',
            'le.lead.list.today',
            'le.lead.list.tomorrow',
            'le.lead.list.yesterday',
            'le.lead.list.week_last',
            'le.lead.list.week_next',
            'le.lead.list.week_this',
            'le.lead.list.year_last',
            'le.lead.list.year_next',
            'le.lead.list.year_this',
            'le.lead.list.year_till',
            'le.lead.list.anniversary',
            'le.lead.list.last_03',
            'le.lead.list.next_03',
            'le.lead.list.last_07',
            'le.lead.list.next_07',
            'le.lead.list.last_15',
            'le.lead.list.next_15',
            'le.lead.list.last_30',
            'le.lead.list.next_30',
            'le.lead.list.last_60',
            'le.lead.list.next_60',
            'le.lead.list.last_90',
            'le.lead.list.next_90',
        ];
    }

    /**
     * @return string
     */
    protected function getDefaultOrder()
    {
        return [
            ['l.name', 'ASC'],
        ];
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'l';
    }

    /**
     * If there is a negate comparison such as not equal, empty, isNotLike or isNotIn then contacts without companies should
     * be included but the way the relationship is handled needs to be different to optimize best for a posit vs negate.
     *
     * @param QueryBuilder $q
     * @param string       $leadTablePrefix
     */
    private function applyCompanyFieldFilters(QueryBuilder $q, $leadTablePrefix = 'l')
    {
        $joinType = ($this->listFiltersInnerJoinCompany) ? 'join' : 'leftJoin';
        // Join company tables for query optimization
        $q->$joinType($leadTablePrefix, MAUTIC_TABLE_PREFIX.'companies_leads', 'cl', "$leadTablePrefix.id = cl.lead_id")
            ->$joinType(
                'cl',
                MAUTIC_TABLE_PREFIX.'companies',
                'comp',
                'cl.company_id = comp.id'
            );

        // Return only unique contacts
        $q->groupBy("$leadTablePrefix.id");
    }

    public function getTotalSegmentCount($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('count(*) as totalsegment')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'l');

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('l.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['totalsegment'];
    }

    public function getTotalActiveSegmentCount($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(*) as activesegment')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'l')
            ->andWhere('l.is_published = 1 ');

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('l.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['activesegment'];
    }

    public function getTotalInactiveSegmentCount($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('count(*) as inactivesegment')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'l')
            ->andWhere('l.is_published != 1 ');

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('l.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('l.created_by', ':id'))
                ->setParameter('id', '1');
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['inactivesegment'];
    }

    public function checkConditionFilter($lead, $filters)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');
        $q->andWhere(
            $q->expr()->eq('l.id', $lead->getId()));
        $parameters=[];
        $expr      = $this->generateSegmentExpression($filters, $parameters, $q, null, null, false, 'l', $lead->getId());
        if ($expr->count()) {
            $q->andWhere($expr);
        }
        unset($parameters);
        $sql=$q->getSql();
        // file_put_contents("/var/www/mauto/campaign_tracker.txt","SQL:".$sql."\n",FILE_APPEND);
        return  (bool) $q->execute()->fetchColumn();
    }
}
