<?php

/**
 * This file is part of hofff/contao-content.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author     Carsten Götzinger <info@cgo-it.de>
 * @author     David Molineus <david@hofff.com>
 * @copyright  2013-2018 cgo IT.
 * @copyright  2019 hofff.com.
 * @license    https://github.com/hofff/contao-rate-it/blob/master/LICENSE LGPL-3.0-or-later
 * @filesource
 */

namespace Hofff\Contao\RateIt\Controller;

use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Database;
use Contao\FrontendUser;
use Doctrine\DBAL\Connection;
use Hofff\Contao\RateIt\Frontend\RateItFrontend;
use PDO;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\TranslatorInterface;

class AjaxRateItController
{
    /** @var ContaoFrameworkInterface */
    private $framework;

    /** @var RateItFrontend */
    private $rateItFrontend;

    /** @var bool */
    private $allowDuplicates;

    /** @var bool */
    private $allowDuplicatesForMembers;

    /** @var Database */
    private $Database;

    /** @var Connection */
    private $connection;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(
        Connection $connection,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
        ContaoFrameworkInterface $framework
    )
    {
        $this->framework    = $framework;
        $this->connection   = $connection;
        $this->tokenStorage = $tokenStorage;
        $this->translator   = $translator;
    }

    public function ajaxAction(Request $request) : Response
    {
        $this->framework->initialize();
        $this->Database = Database::getInstance();

        // See #4099
        if (! defined('BE_USER_LOGGED_IN')) {
            define('BE_USER_LOGGED_IN', false);
        }
        if (! defined('FE_USER_LOGGED_IN')) {
            define('FE_USER_LOGGED_IN', false);
        }

        $this->rateItFrontend = new RateItFrontend();

        $this->allowDuplicates           = (bool)$GLOBALS['TL_CONFIG']['rating_allow_duplicate_ratings'];
        $this->allowDuplicatesForMembers = (bool)$GLOBALS['TL_CONFIG']['rating_allow_duplicate_ratings_for_members'];

        return $this->doVote($request);
    }

    /**
     * doVote
     *
     * This is the function in charge of handling a vote and saving it to the
     * database.
     *
     * NOTE: This method is meant to be called as part of an AJAX request.  As
     * such, it unitlizes the die() function to display its errors.  THIS
     * WOULD BE A VERY BAD FUNCTION TO CALL FROM WITHIN ANOTHER PAGE.
     *
     * @param integer id      - The id of key to register a rating for.
     * @param integer percent - The rating in percentages.
     */
    public function doVote(Request $request)
    {
        $clientIp = $request->getClientIp();
        $rkey     = $request->request->get('id');
        $percent  = $request->request->get('vote');
        $type     = $request->request->get('type');
        $id       = null;

        //Make sure that the ratable ID is a number and not something crazy.
        if (false !== strpos($rkey, '|')) {
            $arrRkey = explode('|', $rkey);
            foreach ($arrRkey as $key) {
                if (! is_numeric($key)) {
                    return new JsonResponse(
                        $this->translator->trans('rateit.error.invalid_rating', [], 'contao_default'),
                        400
                    );
                }
                $id = $rkey;
            }
        } else {
            if (is_numeric($rkey)) {
                $id = $rkey;
            } else {
                return new JsonResponse(
                    $this->translator->trans('rateit.error.invalid_rating', [], 'contao_default'),
                    400
                );
            }
        }

        //Make sure the percent is a number and under 100.
        if (is_numeric($percent) && $percent < 101) {
            $rating = $percent;
        } else {
            return new JsonResponse(
                $this->translator->trans('rateit.error.invalid_rating', [], 'contao_default'),
                400
            );
        }

        //Make sure that the ratable type is 'page' or 'ce' or 'module'
        if (! ($type === 'page' || $type === 'article' || $type === 'ce' || $type === 'module' || $type === 'news' || $type === 'faq' || $type === 'galpic' || $type === 'news4ward')) {
            return new JsonResponse(
                $this->translator->trans('rateit.error.invalid_type', ['type' => $type], 'contao_default'),
                400
            );
        }

        $userId = $this->determineUserId();
        $ratableKeyId = $this->getRateableKeyId($id, $type);
        $countUserRatings = $this->countUserRating($userId, $ratableKeyId);

        $countIp = $this->Database->prepare('SELECT * FROM tl_rateit_ratings WHERE pid=? and ip_address=?')
            ->execute($ratableKeyId, $clientIp)
            ->count();

        // Die with an error if the insert fails (duplicate IP or duplicate member id for a vote).
        if ((! $this->allowDuplicatesForMembers && $countUserRatings === 0) || ($this->allowDuplicatesForMembers && isset($userId))) {
            // Insert the data.
            $arrSet = array('pid'        => $ratableKeyId,
                            'tstamp'     => time(),
                            'ip_address' => $clientIp,
                            'memberid'   => isset($userId) ? $userId : null,
                            'rating'     => $rating,
                            'createdat'  => time(),
            );
            $this->connection->insert('tl_rateit_ratings', $arrSet);
        } elseif (! isset($userId) && ((! $this->allowDuplicates && $countIp == 0) || $this->allowDuplicates)) {
            // Insert the data.
            $arrSet = array('pid'        => $ratableKeyId,
                            'tstamp'     => time(),
                            'ip_address' => $clientIp,
                            'memberid'   => isset($userId) ? $userId : null,
                            'rating'     => $rating,
                            'createdat'  => time(),
            );

            $this->connection->insert('tl_rateit_ratings', $arrSet);
        } else {
            return new JsonResponse(
                $this->translator->trans('rateit.error.duplicate_vote', [], 'contao_default'),
                400
            );
        }

        $rating = $this->rateItFrontend->loadRating($id, $type);

        return new JsonResponse($this->rateItFrontend->getStarMessage($rating));
    }

    private function determineUserId() : ?int
    {
        $token = $this->tokenStorage->getToken();
        if (! $token) {
            return null;
        }

        $user = $token->getUser();
        if ($user instanceof FrontendUser) {
            return (int)$user->id;
        }

        return null;
    }

    protected function getRateableKeyId($id, string $type) : string
    {
        $statement = $this->connection->prepare('SELECT id FROM tl_rateit_items WHERE rkey=:id and typ=:type');
        $statement->bindValue('id', $id);
        $statement->bindValue('type', $type);
        $statement->execute();

        return $statement->fetch(PDO::FETCH_COLUMN);
    }

    private function countUserRating(?int $userId, string $ratableKeyId) : int
    {
        if (!$userId) {
            return 0;
        }

        $statement = $this->connection->prepare(
            'SELECT count(*) FROM tl_rateit_ratings WHERE pid=:pid and memberid=:memberid'
        );

        $statement->bindValue('pid', $ratableKeyId);
        $statement->bindValue('memberid', $userId);
        $statement->execute();

        return (int) $statement->fetch(\PDO::FETCH_COLUMN);
    }
}
