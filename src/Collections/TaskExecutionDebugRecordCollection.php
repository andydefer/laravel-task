<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;

/**
 * @extends AbstractTypedCollection<TaskExecutionDebugRecord>
 */
final class TaskExecutionDebugRecordCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskExecutionDebugRecord::class);
    }

    /**
     * Filtre les enregistrements par statut.
     *
     * @param  ExecutionStatus  $status  Statut à filtrer
     * @return self Nouvelle collection avec les enregistrements correspondants
     */
    public function filterByStatus(ExecutionStatus $status): self
    {
        $collection = new self;
        foreach ($this->items as $item) {
            if ($item->status === $status) {
                $collection->add($item);
            }
        }

        return $collection;
    }

    /**
     * Filtre les enregistrements par alias.
     *
     * @param  TaskAliasVO  $alias  Alias à filtrer
     * @return self Nouvelle collection avec les enregistrements correspondants
     */
    public function filterByAlias(TaskAliasVO $alias): self
    {
        $collection = new self;
        foreach ($this->items as $item) {
            if ($item->alias->getValue() === $alias->getValue()) {
                $collection->add($item);
            }
        }

        return $collection;
    }

    /**
     * Filtre les enregistrements par FQCN.
     *
     * @param  TaskFqcnVO  $fqcn  FQCN à filtrer
     * @return self Nouvelle collection avec les enregistrements correspondants
     */
    public function filterByFqcn(TaskFqcnVO $fqcn): self
    {
        $collection = new self;
        foreach ($this->items as $item) {
            if ($item->fqcn->getValue() === $fqcn->getValue()) {
                $collection->add($item);
            }
        }

        return $collection;
    }

    /**
     * Filtre les enregistrements par plage de dates.
     *
     * @param  Iso8601DateTimeVO  $from  Date de début
     * @param  Iso8601DateTimeVO  $to  Date de fin
     * @return self Nouvelle collection avec les enregistrements correspondants
     */
    public function filterByDateRange(Iso8601DateTimeVO $from, Iso8601DateTimeVO $to): self
    {
        $collection = new self;
        $fromTimestamp = strtotime($from->value);
        $toTimestamp = strtotime($to->value);

        foreach ($this->items as $item) {
            if ($item->started_at !== null) {
                $itemTimestamp = strtotime($item->started_at->value);
                if ($itemTimestamp >= $fromTimestamp && $itemTimestamp <= $toTimestamp) {
                    $collection->add($item);
                }
            }
        }

        return $collection;
    }

    /**
     * Récupère uniquement les enregistrements réussis.
     *
     * @return self Nouvelle collection avec les enregistrements réussis
     */
    public function succeeded(): self
    {
        return $this->filterByStatus(ExecutionStatus::SUCCEEDED);
    }

    /**
     * Récupère uniquement les enregistrements en échec.
     *
     * @return self Nouvelle collection avec les enregistrements en échec
     */
    public function failed(): self
    {
        return $this->filterByStatus(ExecutionStatus::FAILED);
    }

    /**
     * Récupère les alias uniques présents dans la collection.
     *
     * @return array<string> Liste des alias uniques
     */
    public function getUniqueAliases(): array
    {
        $aliases = [];
        foreach ($this->items as $item) {
            $aliases[] = $item->alias->getValue();
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Récupère les FQCN uniques présents dans la collection.
     *
     * @return array<string> Liste des FQCN uniques
     */
    public function getUniqueFqcns(): array
    {
        $fqcns = [];
        foreach ($this->items as $item) {
            $fqcns[] = $item->fqcn->getValue();
        }

        return array_values(array_unique($fqcns));
    }

    /**
     * Calcule la durée moyenne d'exécution.
     *
     * @return float|null Durée moyenne en secondes, null si aucun enregistrement
     */
    public function getAverageDuration(): ?float
    {
        if ($this->isEmpty()) {
            return null;
        }

        $total = 0.0;
        $count = 0;

        foreach ($this->items as $item) {
            if ($item->started_at !== null && $item->ended_at !== null) {
                $start = strtotime($item->started_at->value);
                $end = strtotime($item->ended_at->value);
                $total += ($end - $start);
                $count++;
            }
        }

        return $count > 0 ? $total / $count : null;
    }

    /**
     * Obtient les statistiques des statuts.
     *
     * @return array<string, int> Tableau associatif [status => count]
     */
    public function getStatusStats(): array
    {
        $stats = [];
        foreach ($this->items as $item) {
            $status = $item->status->value;
            $stats[$status] = ($stats[$status] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Trie les enregistrements par date de début (ordre chronologique).
     *
     * @param  bool  $ascending  True pour ascendant, false pour descendant
     * @return self Nouvelle collection triée
     */
    public function sortByStartedAt(bool $ascending = true): self
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($ascending) {
            if ($a->started_at === null && $b->started_at === null) {
                return 0;
            }
            if ($a->started_at === null) {
                return 1;
            }
            if ($b->started_at === null) {
                return -1;
            }

            $comparison = strtotime($a->started_at->value) <=> strtotime($b->started_at->value);

            return $ascending ? $comparison : -$comparison;
        });

        $collection = new self;
        foreach ($items as $item) {
            $collection->add($item);
        }

        return $collection;
    }
}
