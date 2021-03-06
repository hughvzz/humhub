<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\content\components;

use humhub\modules\content\models\ContentTag;
use Yii;
use humhub\modules\user\models\User;
use humhub\modules\space\models\Space;

/**
 * ActiveQueryContent is an enhanced ActiveQuery with additional selectors for especially content.
 * 
 * @inheritdoc
 *
 * @author luke
 */
class ActiveQueryContent extends \yii\db\ActiveQuery
{

    /**
     * Own content scope for userRelated 
     * @see ActiveQueryContent::userRelated
     */
    const USER_RELATED_SCOPE_OWN = 1;
    const USER_RELATED_SCOPE_SPACES = 2;
    const USER_RELATED_SCOPE_FOLLOWED_SPACES = 3;
    const USER_RELATED_SCOPE_FOLLOWED_USERS = 4;
    const USER_RELATED_SCOPE_OWN_PROFILE = 5;

    /**
     * Only returns user readable records
     * 
     * @param \humhub\modules\user\models\User $user
     * @return \humhub\modules\content\components\ActiveQueryContent
     */
    public function readable($user = null)
    {
        if ($user === null && !Yii::$app->user->isGuest) {
            $user = Yii::$app->user->getIdentity();
        }

        $this->joinWith(['content', 'content.contentContainer', 'content.createdBy']);

        $this->leftJoin('space', 'contentcontainer.pk=space.id AND contentcontainer.class=:spaceClass', [':spaceClass' => Space::className()]);
        $this->leftJoin('user cuser', 'contentcontainer.pk=cuser.id AND contentcontainer.class=:userClass', [':userClass' => User::className()]);
        $conditionSpace = '';
        $conditionUser = '';

        if ($user !== null) {
            $this->leftJoin('space_membership', 'contentcontainer.pk=space_membership.space_id AND contentcontainer.class=:spaceClass AND space_membership.user_id=:userId', [':userId' => $user->id, ':spaceClass' => Space::className()]);

            // Build Access Check based on Space Content Container
            $conditionSpace = 'space.id IS NOT NULL AND (';                                         // space content
            $conditionSpace .= ' (space_membership.status=3)';                                      // user is space member
            $conditionSpace .= ' OR (content.visibility=1 AND space.visibility != 0)';               // visibile space and public content
            $conditionSpace .= ')';

            // Build Access Check based on User Content Container
            $conditionUser = 'cuser.id IS NOT NULL AND (';                                         // user content
            $conditionUser .= '   (content.visibility = 1) OR';                                     // public visible content
            $conditionUser .= '   (content.visibility = 0 AND content.contentcontainer_id=' . $user->contentContainerRecord->id . ')';  // private content of user
            if (Yii::$app->getModule('friendship')->getIsEnabled()) {
                $this->leftJoin('user_friendship cff', 'cuser.id=cff.user_id AND cff.friend_user_id=:fuid', [':fuid' => $user->id]);
                $conditionUser .= ' OR (content.visibility = 0 AND cff.id IS NOT NULL)';  // users are friends
            }
            $conditionUser .= ')';

            // Created content of is always visible
            $conditionUser .= 'OR content.created_by=' . $user->id;
        } elseif (Yii::$app->user->isGuestAccessEnabled()) {
            $conditionSpace = 'space.id IS NOT NULL and space.visibility=' . Space::VISIBILITY_ALL . ' AND content.visibility=1';
            $conditionUser = 'cuser.id IS NOT NULL and cuser.visibility=' . User::VISIBILITY_ALL . ' AND content.visibility=1';
        } else {
            $this->emulateExecution();
        }

        $this->andWhere("{$conditionSpace} OR {$conditionUser}");



        return $this;
    }

    /**
     * Limits the returned records to the given ContentContainer.
     * 
     * @param ContentContainerActiveRecord $container|null or null for global content
     * @return \humhub\modules\content\components\ActiveQueryContent
     * @throws \yii\base\Exception
     */
    public function contentContainer($container)
    {
        if ($container === null) {
            $this->joinWith(['content', 'content.contentContainer', 'content.createdBy']);
            $this->andWhere(['IS', 'contentcontainer.pk', new \yii\db\Expression('NULL')]);
        } else {
            $this->joinWith(['content', 'content.contentContainer', 'content.createdBy']);
            $this->andWhere(['contentcontainer.pk' => $container->id, 'contentcontainer.class' => $container->className()]);
        }

        return $this;
    }

    /**
     * Filters contents by given contentTags.
     *
     * @param array|string|ContentTag $contentTags array of or single content tag classes or instances
     * @return $this
     * @since 1.3
     */
    public function contentTag($contentTags = [])
    {
        if(empty($contentTag)) {
            return $this;
        }

        if(!is_array($contentTags)) {
            $contentTags = [$contentTags];
        }

        $this->innerJoinWith('tags');
        foreach ($contentTags as $contentTag) {
            $contentTagClass = null;
            if($contentTag instanceof ContentTag) {
                $contentTagClass = get_class($contentTag);
            } elseif(is_string($contentTag)) {
                $contentTagClass = $contentTag;
            }

            if($contentTagClass) {
                call_user_func($contentTagClass .'::addQueryCondition', $this);
            }
        }

        return $this;
    }

    /**
     * Adds an additional WHERE condition to the existing one.
     * 
     * @inheritdoc
     * 
     * @param array|string $condition
     * @param array $params
     * @return $this
     */
    public function where($condition, $params = [])
    {
        return parent::andWhere($condition, $params);
    }

    /**
     * Finds user related content.
     * All available scopes: ActiveQueryContent::USER_RELATED_SCOPE_*
     * 
     * @param array $scopes 
     * @param User $user
     * @return \humhub\modules\content\components\ActiveQueryContent
     */
    public function userRelated($scopes = [], $user = null)
    {
        if ($user === null) {
            $user = Yii::$app->user->getIdentity();
        }

        $this->joinWith(['content', 'content.contentContainer']);

        $conditions = [];
        $params = [];

        if (in_array(self::USER_RELATED_SCOPE_OWN_PROFILE, $scopes)) {
            $conditions[] = 'contentcontainer.pk=:userId AND class=:userClass';
            $params[':userId'] = $user->id;
            $params[':userClass'] = $user->className();
        }

        if (in_array(self::USER_RELATED_SCOPE_SPACES, $scopes)) {
            $spaceMemberships = (new \yii\db\Query())
                    ->select("sm.id")
                    ->from('space_membership')
                    ->leftJoin('space sm', 'sm.id=space_membership.space_id')
                    ->where('space_membership.user_id=:userId AND space_membership.status=' . \humhub\modules\space\models\Membership::STATUS_MEMBER);
            $conditions[] = 'contentcontainer.pk IN (' . Yii::$app->db->getQueryBuilder()->build($spaceMemberships)[0] . ') AND contentcontainer.class = :spaceClass';
            $params[':userId'] = $user->id;
            $params[':spaceClass'] = Space::className();
        }

        if (in_array(self::USER_RELATED_SCOPE_OWN, $scopes)) {
            $conditions[] = 'content.created_by = :userId';
            $params[':userId'] = $user->id;
        }

        if (in_array(self::USER_RELATED_SCOPE_FOLLOWED_SPACES, $scopes)) {
            $spaceFollow = (new \yii\db\Query())
                    ->select("sf.id")
                    ->from('user_follow')
                    ->leftJoin('space sf', 'sf.id=user_follow.object_id AND user_follow.object_model=:spaceClass')
                    ->where('user_follow.user_id=:userId AND sf.id IS NOT NULL');
            $conditions[] = 'contentcontainer.pk IN (' . Yii::$app->db->getQueryBuilder()->build($spaceFollow)[0] . ') AND contentcontainer.class = :spaceClass';
            $params[':spaceClass'] = Space::className();
            $params[':userId'] = $user->id;
        }

        if (in_array(self::USER_RELATED_SCOPE_FOLLOWED_USERS, $scopes)) {
            $userFollow = (new \yii\db\Query())
                    ->select(["uf.id"])
                    ->from('user_follow')
                    ->leftJoin('user uf', 'uf.id=user_follow.object_id AND user_follow.object_model=:userClass')
                    ->where('user_follow.user_id=:userId AND uf.id IS NOT NULL');
            $conditions[] = 'contentcontainer.pk IN (' . Yii::$app->db->getQueryBuilder()->build($userFollow)[0] . ' AND contentcontainer.class=:userClass)';
            $params[':userClass'] = User::className();
            $params[':userId'] = $user->id;
        }

        if (count($conditions) != 0) {
            $this->andWhere("(" . join(') OR (', $conditions) . ")", $params);
        } else {
            // No results, when no selector given
            $this->andWhere('1=2');
        }

        return $this;
    }

}
