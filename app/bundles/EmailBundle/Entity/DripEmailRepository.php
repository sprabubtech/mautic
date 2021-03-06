<?php

/*
 * @copyright   2018 LeadsEngage Contributors. All rights reserved
 * @author      LeadsEngage
 *
 * @link        https://leadsengage.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Entity;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * Class DripEmailRepository.
 */
class DripEmailRepository extends CommonRepository
{
    /**
     * Get a list of entities.
     *
     * @param array $args
     *
     * @return Paginator
     */
    public function getEntities(array $args = [])
    {
        $q = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('d')
            ->from('MauticEmailBundle:DripEmail', 'd', 'd.id');
        if (empty($args['iterator_mode'])) {
            $q->leftJoin('d.category', 'c');
        }

        $args['qb'] = $q;

        return parent::getEntities($args);
    }

    /**
     * @return string
     */
    protected function getDefaultOrder()
    {
        return [
            ['d.name', 'ASC'],
        ];
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'd';
    }

    public function updateFromInfoinEmail(DripEmail $entity)
    {
        $dripId = $entity->getId();

        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->update(MAUTIC_TABLE_PREFIX.'emails')
            ->set('from_name', ':fromName')
            ->set('from_address', ':fromAddress')
            ->set('reply_to_address', ':replyToAddress')
            ->set('bcc_address', ':BccAddress')
            ->set('unsubscribe_text', ':UnsubscribeText')
            ->set('postal_address', ':postalAddress')
            ->setParameter('fromName', $entity->getFromName())
            ->setParameter('fromAddress', $entity->getFromAddress())
            ->setParameter('replyToAddress', $entity->getReplyToAddress())
            ->setParameter('BccAddress', $entity->getBccAddress())
            ->setParameter('UnsubscribeText', $entity->getUnsubscribeText())
            ->setParameter('postalAddress', $entity->getPostalAddress())
            ->where(
                $qb->expr()->eq('dripemail_id', $dripId)
            )
            ->execute();
    }

    public function updateUtmInfoinEmail(DripEmail $entity, $email)
    {
        $dripEmailsRepository   = $email->getRepository();
        $dripemails             = $dripEmailsRepository->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $entity,
                        ],
                        [
                            'column' => 'e.emailType',
                            'expr'   => 'eq',
                            'value'  => 'dripemail',
                        ],
                    ],
                ],
                'ignore_paginator' => true,
            ]
        );

        foreach ($dripemails as $dripemail) {
            if ($entity->isGoogleTags()) {
                if (empty($currentutmtags['utmSource'])) {
                    $currentutmtags['utmSource'] = 'AnyFunnels';
                }
                if (empty($currentutmtags['utmMedium'])) {
                    $currentutmtags['utmMedium'] = 'email';
                }
                if (empty($currentutmtags['utmCampaign'])) {
                    $currentutmtags['utmCampaign'] = 'DripEmail - '.$dripemail->getSubject();
                }
                if (empty($currentutmtags['utmContent'])) {
                    $currentutmtags['utmContent'] = $dripemail->getSubject();
                }
            } else {
                $currentutmtags['utmSource']  = null;
                $currentutmtags['utmMedium']  = null;
                $currentutmtags['utmCampaign']=null;
                $currentutmtags['utmContent'] =null;
            }
            $dripemail->setUtmTags($currentutmtags);
            $dripEmailsRepository->saveEntity($dripemail);
        }
    }

    /**
     * Get sent counts based on date(Last 30 Days).
     *
     * @param array $emailIds
     *
     * @return array
     */
    public function getLast30DaysDripSentCounts($viewOthers =false)
    {
        $fromdate = date('Y-m-d', strtotime('-29 days'));

        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('count(e.id) as sentcount')
            ->from(MAUTIC_TABLE_PREFIX.'email_stats', 'es')
            ->leftJoin('es', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = es.email_id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('es.is_failed', ':false')
                )
            )->setParameter('false', false, 'boolean');

        $q->andWhere($q->expr()->eq('e.email_type', ':emailType'),
            $q->expr()->neq('e.dripemail_id', '"NULL"'))
            ->setParameter('emailType', 'dripemail');

        if ($fromdate !== null) {
            $q->andWhere(
                $q->expr()->gte('es.date_sent', $q->expr()->literal($fromdate))
            );
        }

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('e.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('e.created_by', ':id'))
                ->setParameter('id', '1');
        }

        //get a total number of sent emails
        $results = $q->execute()->fetchAll();

        return $results[0]['sentcount'];
    }

    /**
     * Get open counts based on date.
     *
     * @param array $emailIds
     *
     * @return array
     */
    public function getLast30DaysDripOpensCounts($viewOthers =false)
    {
        $fromdate = date('Y-m-d', strtotime('-29 days'));

        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('count(e.id) as opencount')
            ->from(MAUTIC_TABLE_PREFIX.'email_stats', 'es')
            ->leftJoin('es', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = es.email_id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('es.is_failed', ':false')
                )
            )->setParameter('false', false, 'boolean');

        $q->andWhere($q->expr()->eq('e.email_type', ':emailType'),
            $q->expr()->neq('e.dripemail_id', '"NULL"'))
            ->setParameter('emailType', 'dripemail');

        if ($fromdate !== null) {
            $q->andWhere(
                $q->expr()->gte('es.date_read', $q->expr()->literal($fromdate))
            );
            $q->andWhere(
                $q->expr()->eq('es.is_read', 1)
            );
        }

        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('e.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('e.created_by', ':id'))
                ->setParameter('id', '1');
        }

        //get a total number of sent emails
        $results = $q->execute()->fetchAll();

        return $results[0]['opencount'];
    }

    /**
     * Get open counts based on date.
     *
     * @param array $emailIds
     *
     * @return array
     */
    public function getLast30DaysDripClickCounts($viewOthers =false)
    {
        $dateinterval = date('Y-m-d', strtotime('-29 days'));
        $q            = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $q->select('t.unique_hits,t.channel_id')
            ->from(MAUTIC_TABLE_PREFIX.'page_redirects', 'r')
            ->leftJoin('r', MAUTIC_TABLE_PREFIX.'channel_url_trackables', 't',
                $q->expr()->andX(
                    $q->expr()->eq('r.id', 't.redirect_id'),
                    $q->expr()->eq('t.channel', ':channel')
                )
            )
            ->setParameter('channel', 'email')
            ->leftJoin('t', MAUTIC_TABLE_PREFIX.'email_stats', 'es',
                $q->expr()->andX(
                    $q->expr()->eq('t.channel_id', 'es.id')
                ))
            ->andWhere($q->expr()->gte('r.date_added', ':dateAdded'))
            ->setParameter('dateAdded', $dateinterval)
            ->orderBy('r.url');

        $results       = $q->execute()->fetchAll();
        $sq            = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $sq->select('id')
            ->from(MAUTIC_TABLE_PREFIX.'emails')
            ->andWhere($sq->expr()->eq('email_type', '"dripemail"'));
        if (!$viewOthers) {
            $sq->andWhere($sq->expr()->eq('created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $sq->andWhere($sq->expr()->neq('created_by', ':id'))
                ->setParameter('id', '1');
        }
        $ids   = $sq->execute()->fetchAll();
        $result=0;
        for ($i =0; $i < sizeof($results); ++$i) {
            for ($j=0; $j < sizeof($ids); ++$j) {
                if ($results[$i]['channel_id'] == $ids[$j]['id']) {
                    $result += $results[$i]['unique_hits'];
                }
            }
        }

        return $result;

        /*  $dateinterval = date('Y-m-d', strtotime('-29 days'));
          $q            = $this->getEntityManager()->getConnection()->createQueryBuilder();
          $q->select('count(e.id) as clickcount')
              ->from(MAUTIC_TABLE_PREFIX.'page_hits', 'ph')
              ->leftJoin('ph', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = ph.email_id')
              ->where(
                  $q->expr()->andX(
                      $q->expr()->gte('ph.date_hit', ':clickdate')
                  )
              )->setParameter('clickdate', $dateinterval);
          $q->andWhere($q->expr()->eq('e.email_type', ':emailType'),
              $q->expr()->neq('e.dripemail_id', '"NULL"'))
              ->setParameter('emailType', 'dripemail');
          if (!$viewOthers) {
              $q->andWhere($q->expr()->eq('e.created_by', ':currentUserId'))
                  ->setParameter('currentUserId', $this->currentUser->getId());
          }

          if ($this->currentUser->getId() != 1) {
              $q->andWhere($q->expr()->neq('e.created_by', ':id'))
                  ->setParameter('id', '1');
          }

          $results = $q->execute()->fetchAll();

          return $results[0]['clickcount'];*/
    }

    public function getDripUnsubscribeCounts($viewOthers = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('count(e.id) as unsubscribecount')
            ->from(MAUTIC_TABLE_PREFIX.'email_stats', 'es')
            ->leftJoin('es', MAUTIC_TABLE_PREFIX.'emails', 'e', 'e.id = es.email_id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('es.is_failed', ':false')
                )
            )->setParameter('false', false, 'boolean');

        $q->andWhere('e.email_type = :emailType')
            ->setParameter('emailType', 'dripemail');
        $q->andWhere(
            $q->expr()->eq('es.is_unsubscribe', 1),
            $q->expr()->neq('e.dripemail_id', '"NULL"')
        );
        if (!$viewOthers) {
            $q->andWhere($q->expr()->eq('e.created_by', ':currentUserId'))
                ->setParameter('currentUserId', $this->currentUser->getId());
        }

        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('e.created_by', ':id'))
                ->setParameter('id', '1');
        }

        //get a total number of sent emails
        $results = $q->execute()->fetchAll();

        return $results[0]['unsubscribecount'];
    }

    /**
     * @param string $search
     * @param int    $limit
     * @param int    $start
     * @param bool   $viewOther
     * @param null   $emailType
     * @param array  $ignoreIds
     *
     * @return array
     */
    public function getDripEmailList($search = '', $limit = 10, $start = 0, $viewOther = false, array $ignoreIds = [])
    {
        $q = $this->createQueryBuilder('d');
        $q->select('partial d.{id, subject, name}');

        if (!empty($search)) {
            if (is_array($search)) {
                $search = array_map('intval', $search);
                $q->andWhere($q->expr()->in('d.id', ':search'))
                    ->setParameter('search', $search);
            } else {
                $q->andWhere($q->expr()->like('d.name', ':search'))
                    ->setParameter('search', "%{$search}%");
            }
        }
        $q->andWhere($q->expr()->eq('d.isPublished', ':val'))
            ->setParameter('val', '1');

        /*      if (!$viewOther) {
                  $q->andWhere($q->expr()->eq('d.createdBy', ':id'))
                      ->setParameter('id', $this->currentUser->getId());
              }*/
        if ($this->currentUser->getId() != 1) {
            $q->andWhere($q->expr()->neq('d.createdBy', ':id'))
                ->setParameter('id', '1');
        }

        if (!empty($ignoreIds)) {
            $q->andWhere($q->expr()->notIn('d.id', ':dripEmailIds'))
                ->setParameter('dripEmailIds', $ignoreIds);
        }

        $q->orderBy('d.name');

        if (!empty($limit)) {
            $q->setFirstResult($start)
                ->setMaxResults($limit);
        }

        return $q->getQuery()->getArrayResult();
    }

    public function getAllDripEmailList()
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('e.id as id,e.subject as name,d.name as dripname')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->leftJoin('e', MAUTIC_TABLE_PREFIX.'dripemail', 'd', 'e.dripemail_id = d.id')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('e.email_type', ':emailType')
                )
            )->setParameter('emailType', 'dripemail');

        $q->andWhere($q->expr()->eq('d.is_published', ':isPublished'))
            ->setParameter('isPublished', '1');

        $q->andWhere($q->expr()->isNotNull('e.dripemail_id'));

        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function getEmailIdsByDrip($dripId)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->select('e.id as id')
            ->from(MAUTIC_TABLE_PREFIX.'emails', 'e')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('e.email_type', ':emailType')
                )
            )->andWhere($q->expr()->andX(
                    $q->expr()->eq('e.dripemail_id', ':dripemailID')
                )
            )
            ->setParameter('emailType', 'dripemail')
            ->setParameter('dripemailID', $dripId);

        $results  = $q->execute()->fetchAll();
        $response = [];
        foreach ($results as $id) {
            $response[] = $id['id'];
        }

        return $response;
    }

    public function getLeadsByDrip($drip, $countOnly, $returnQuery = false, $limit=false)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $dlQ = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $dlQ->select('dl.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'dripemail_leads', 'dl')
            ->andWhere($dlQ->expr()->eq('dl.dripemail_id', $drip->getId()));

        if ($countOnly) {
            // distinct with an inner join seems faster
            $q->select('count(distinct(l.id)) as count');
        } else {
            $q->select('l.*');
        }
        if ($drip instanceof DripEmail) {
            if (isset($drip->getRecipients()['filters']) && !empty($drip->getRecipients()['filters'])) {
                $leadlistRepo = $this->getEntityManager()->getRepository('MauticLeadBundle:LeadList');
                $parameters   =[];
                $expr         = $leadlistRepo->generateSegmentExpression($drip->getRecipients()['filters'], $parameters, $q, null, null, false, 'l', null);
                if ($expr->count()) {
                    $q->andWhere($expr);
                }
            } else {
                return ($countOnly) ? 0 : [];
            }
        } else {
            return ($countOnly) ? 0 : [];
        }
        $q->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->andWhere(sprintf('l.id NOT IN (%s)', $dlQ->getSQL()))
            ->andwhere($q->expr()->notIn('l.status', ['3', '4', '5', '6'])); //Invalid,Complaint,Unsubscribed,NotConfirmed Leads

        if ($limit) {
            $q->setFirstResult(0)
                ->setMaxResults($limit);
        }
        $results = $q->execute()->fetchAll();
        if ($returnQuery) {
            return $q;
        }
        if ($countOnly) {
            return (isset($results[0])) ? $results[0]['count'] : 0;
        } else {
            $leads = [];
            foreach ($results as $r) {
                $leads[$r['id']] = $r;
            }

            return $leads;
        }
    }

    public function getDripByLead($leadId, $publishedonly = true)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('dl.dripemail_id as dripId')
            ->from(MAUTIC_TABLE_PREFIX.'dripemail_leads', 'dl');

        if ($publishedonly) {
            $q->leftJoin('dl', MAUTIC_TABLE_PREFIX.'dripemail', 'd', 'dl.dripemail_id = d.id')
                ->where(
                    $q->expr()->andX(
                        $q->expr()->eq('d.is_published', ':isPublished')
                    )
                )->setParameter('isPublished', $publishedonly);
        }
        $q->andWhere($q->expr()->eq('dl.lead_id', ':leadId'))
            ->setParameter('leadId', $leadId);

        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function getLeadIdsByDrip($drip)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('dl.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'dripemail_leads', 'dl')
            ->Where($q->expr()->eq('dl.dripemail_id', $drip->getId()));

        $results = $q->execute()->fetchAll();

        return $results;
    }

    public function deleteLeadEventLogbyDrip($dripid)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->delete(MAUTIC_TABLE_PREFIX.'dripemail_lead_event_log')
            ->where('dripemail_id = '.(int) $dripid)
            ->execute();
    }

    public function deleteDripEmailsbyDrip($dripid)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->delete(MAUTIC_TABLE_PREFIX.'emails')
            ->where('dripemail_id = '.(int) $dripid)
            ->execute();
    }
}
