<?php

/**
 * This file is part of hofff/contao-rate-it.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author     David Molineus <david@hofff.com>
 * @author     Carsten Götzinger <info@cgo-it.de>
 * @copyright  2019 hofff.com.
 * @copyright  2013-2018 cgo IT.
 * @license    https://github.com/hofff/contao-rate-it/blob/master/LICENSE LGPL-3.0-or-later
 * @filesource
 */

declare(strict_types=1);

namespace Hofff\Contao\RateIt\Rating;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\System;
use Doctrine\DBAL\Connection;
use PDO;

final class RatingService
{
    private const SQL_QUERY = <<<'SQL'

SELECT 
    i.id AS id,   
    i.rkey AS rkey,
    i.title AS title,
    IFNULL(AVG(r.rating),0) AS rating,
    COUNT( r.rating ) AS totalRatings
FROM   
    tl_rateit_items i
LEFT OUTER JOIN 
    tl_rateit_ratings r
    ON i.id = r.pid 
WHERE 
    i.rkey=:rkey and typ=:type and active='1'
GROUP BY i.rkey, i.id, i.title;
SQL;

    /** @var Connection */
    private $connection;

    /** @var ContaoFrameworkInterface */
    private $framework;

    /** @var IsUserAllowedToRate */
    private $isUserAllowedToRate;

    public function __construct(
        Connection $connection,
        ContaoFrameworkInterface $framework,
        IsUserAllowedToRate $isUserAllowedToRate
    )  {
        $this->connection          = $connection;
        $this->framework           = $framework;
        $this->isUserAllowedToRate = $isUserAllowedToRate;
    }

    public function getRating(string $type, int $ratingTypeId, ?int $userId) : ?array
    {
        return $this->getRatingWithMessageTemplate($type, $ratingTypeId, $GLOBALS['TL_CONFIG']['rating_description'], $userId);
    }

    public function getRatingWithSuccessMessage(string $type, int $ratingTypeId, ?int $userId) : ?array
    {
        return $this->getRatingWithMessageTemplate($type, $ratingTypeId, $GLOBALS['TL_CONFIG']['rating_success'] ?: $GLOBALS['TL_CONFIG']['rating_description'], $userId);
    }

    private function getRatingWithMessageTemplate(string $type, int $ratingTypeId, string $template, ?int $userId) : ?array
    {
        $rating = $this->loadRating($ratingTypeId, $type);
        if (! $rating) {
            return null;
        }

        $stars     = $this->percentToStars($rating['rating']);
        $maxStars  = $this->maxStars();
        $sessionId = new CurrentUserId();

        return [
            'descriptionId' => sprintf('rateItRating-%s-description', $ratingTypeId),
            'description'   => $this->getStarMessage($template, $rating),
            'id'            => sprintf('rateItRating-%s-%s-%s_%s', $ratingTypeId, $type, $stars, $maxStars),
            'class'         => 'rateItRating',
            'itemreviewed'  => $rating['title'],
            'actRating'     => $this->percentToStars($rating['rating']),
            'maxRating'     => $maxStars,
            'enabled'       => ($this->isUserAllowedToRate)((int) $rating['id'], (string) $sessionId, $userId),
            'votes'         => $rating['totalRatings'],
            'ratingId'      => $ratingTypeId,
            'ratingType'    => $type,
            'showBefore'    => $this->getConfig('rating_textposition') === 'before',
            'showAfter'     => $this->getConfig('rating_textposition') === 'after',
        ];
    }

    private function loadRating($rkey, $typ) : ?array
    {
        $statement = $this->connection->prepare(self::SQL_QUERY);
        $statement->bindValue('rkey', $rkey);
        $statement->bindValue('type', $typ);
        $result = $statement->executeQuery();

        if ($result->rowCount() === 0) {
            return null;
        }

        return $result->fetchAssociative();
    }

    private function maxStars() : int
    {
        return (int)$this->getConfig('rating_count') ?: 5;
    }

    private function getConfig(string $key)
    {
        $this->framework->initialize();

        return $this->framework->getAdapter(Config::class)->get($key);
    }

    // TODO: Rework
    private function getStarMessage(string $template, ?array $rating) : string
    {
        $this->framework->initialize();
        $this->framework->getAdapter(System::class)->loadLanguageFile('default');

        $stars = $this->percentToStars($rating['rating']);
        preg_match('/^.*\[(.+)\|(.+)\].*$/i', $template, $labels);
        if (! is_array($labels) && (! count($labels) == 2 || ! count($labels) == 3)) {
            $label       = ($rating['totalRatings'] > 1 || $rating['totalRatings'] == 0) || ! $rating ? $GLOBALS['TL_LANG']['rateit']['rating_label'][1] : $GLOBALS['TL_LANG']['rateit']['rating_label'][0];
            $description = '%current%/%max% %type% (%count% [' . $GLOBALS['TL_LANG']['tl_rateit']['vote'][0] . '|' . $GLOBALS['TL_LANG']['tl_rateit']['vote'][1] . '])';
        } else {
            $label       = count($labels) == 2
                ? $labels[1]
                : ($rating['totalRatings'] > 1 || $rating['totalRatings'] == 0 || ! $rating) ? $labels[2] : $labels[1];
            $description = $template;
        }
        $actValue = $rating === false ? 0 : $rating['totalRatings'];
        $type     = $GLOBALS['TL_LANG']['rateit']['stars'];
// 		return str_replace('.', ',', $stars)."/$this->intStars ".$type." ($actValue $label)";
        $description = str_replace('%current%', str_replace('.', ',', $stars), $description);
        $description = str_replace('%max%', $this->maxStars(), $description);
        $description = str_replace('%type%', $type, $description);
        $description = str_replace('%count%', $actValue, $description);
        $description = preg_replace('/^(.*)(\[.*\])(.*)$/i', "\\1$label\\3", $description);
        return $description;
    }

    private function percentToStars($rating) : float
    {
        $modifier = 100 / $this->maxStars();
        return round($rating / $modifier, 1);
    }
}
