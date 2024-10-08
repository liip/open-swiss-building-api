<?php

declare(strict_types=1);

namespace App\Domain\Resolving\Handler\AddressSearch;

use App\Domain\BuildingData\Entity\BuildingEntrance;
use App\Domain\Resolving\Entity\ResolverAddress;
use App\Domain\Resolving\Entity\ResolverAddressMatch;
use App\Domain\Resolving\Entity\ResolverAddressStreet;
use App\Domain\Resolving\Model\Job\ResolverJobIdentifier;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineStreetMatcher
{
    public const string TYPE_STREET_EXACT = 'streetExact';
    public const string TYPE_STREET_EXACT_NORMALIZED = 'streetExactNormalized';

    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @param int<0, 100> $confidence
     */
    public function matchStreetExact(ResolverJobIdentifier $job, int $confidence): void
    {
        $this->matchStreetOn(
            $job,
            $confidence,
            self::TYPE_STREET_EXACT,
            '(a.street_name = b.%street_name% AND a.postal_code = b.postal_code AND a.locality = b.locality)',
        );
    }

    /**
     * @param int<0, 100> $confidence
     */
    public function matchStreetNormalized(ResolverJobIdentifier $job, int $confidence): void
    {
        $this->matchStreetOn(
            $job,
            $confidence,
            self::TYPE_STREET_EXACT_NORMALIZED,
            '(a.street_name_normalized = b.%street_name%_normalized AND a.postal_code = b.postal_code AND a.locality_normalized = b.locality_normalized)',
        );
    }

    /**
     * @param int<0, 100> $confidence
     */
    private function matchStreetOn(ResolverJobIdentifier $job, int $confidence, string $matchType, string $condition): void
    {
        $addressTable = $this->entityManager->getClassMetadata(ResolverAddress::class)->getTableName();
        $buildingEntranceTable = $this->entityManager->getClassMetadata(BuildingEntrance::class)->getTableName();
        $matchTable = $this->entityManager->getClassMetadata(ResolverAddressMatch::class)->getTableName();
        $streetTable = $this->entityManager->getClassMetadata(ResolverAddressStreet::class)->getTableName();

        $onCondition =
            str_replace('%street_name%', 'street_name', $condition) .
            ' OR ' .
            str_replace('%street_name%', 'street_name_abbreviated', $condition);

        $sql = "INSERT INTO {$streetTable} AS t (address_id, street_id, confidence, match_type) " .
            "SELECT a.id, b.street_id, :confidence, :matchType FROM {$addressTable} a " .
            "LEFT JOIN {$matchTable} at ON a.id = at.address_id " .
            "LEFT JOIN {$streetTable} s ON a.id = s.address_id " .
            "INNER JOIN {$buildingEntranceTable} b ON {$onCondition} " .
            'WHERE a.job_id = :jobId AND at.id IS NULL AND s.address_id IS NULL AND a.range_type IS NULL AND b.street_id != :empty ' .
            'GROUP BY a.id, b.street_id';

        $this->entityManager->getConnection()->executeStatement($sql, [
            'jobId' => $job->id,
            'confidence' => $confidence,
            'matchType' => $matchType,
            'empty' => '',
        ]);
    }
}
