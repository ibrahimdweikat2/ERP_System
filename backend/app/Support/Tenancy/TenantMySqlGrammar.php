<?php

namespace App\Support\Tenancy;

use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\JoinClause;

/**
 * Adds the company filter to every query on a company table.
 *
 * The filter goes into the compiled SQL (never the builder), so subqueries,
 * unions, joins, aggregates and pagination clones are each filtered exactly
 * once. The company id is written as a validated integer rather than a bound
 * parameter because subquery bindings are copied into the parent when the
 * subquery is defined, before compilation.
 */
class TenantMySqlGrammar extends MySqlGrammar
{
    // SELECT, UPDATE and DELETE wheres, and every JOIN ... ON.
    public function compileWheres(Builder $query)
    {
        $sql = parent::compileWheres($query);
        $condition = $this->companyCondition($query instanceof JoinClause ? $query->table : $query->from);
        if ($condition === null) {
            return $sql;
        }
        $keyword = $query instanceof JoinClause ? 'on' : 'where';

        return $sql === '' ? "$keyword $condition" : "$keyword $condition and (".substr($sql, strlen($keyword) + 1).')';
    }

    // A nested "(a or b)" group shares its parent's table; the parent already filters.
    protected function whereNested(Builder $query, $where)
    {
        $offset = $where['query'] instanceof JoinClause ? 3 : 6;

        return '('.substr(parent::compileWheres($where['query']), $offset).')';
    }

    public function compileInsert(Builder $query, array $values)
    {
        return parent::compileInsert($query, $this->withCompany($query->from, $values));
    }

    public function compileInsertUsing(Builder $query, array $columns, string $sql)
    {
        if ($this->filters($query->from) && ! in_array('company_id', $columns, true)) {
            throw new MissingCompanyContext("insert...select into [{$query->from}] must name company_id.");
        }

        return parent::compileInsertUsing($query, $columns, $sql);
    }

    public function compileUpdate(Builder $query, array $values)
    {
        $context = $this->context();
        if ($this->filters($query->from)) {
            foreach ($values as $column => $value) {
                if (preg_match('/(^|\.)company_id$/', (string) $column) && $context->mode() !== CompanyContext::BYPASS) {
                    throw new MissingCompanyContext('Rows cannot be moved to another company.');
                }
            }
        }

        return parent::compileUpdate($query, $values);
    }

    public function compileTruncate(Builder $query)
    {
        if ($this->filters($query->from)) {
            throw new MissingCompanyContext("Truncating company table [{$query->from}] is not allowed.");
        }

        return parent::compileTruncate($query);
    }

    public function compileJoins(Builder $query, $joins)
    {
        foreach ($joins as $join) {
            if ($join->type === 'right') {
                throw new MissingCompanyContext('Right joins cannot be company-filtered; use a left join.');
            }
        }

        return parent::compileJoins($query, $joins);
    }

    /** SQL condition for the table, null when no filter applies. Throws when a filter is required but impossible. */
    private function companyCondition(mixed $from): ?string
    {
        if (! is_string($from) || $from === '') {
            return null;
        }
        [$table, $alias] = TenantTables::parse($from);
        $kind = TenantTables::kind($table);
        if ($kind === TenantTables::GLOBAL) {
            return null;
        }
        $context = $this->context();
        $column = $this->wrap($alias.'.company_id');

        return match ($context->mode()) {
            CompanyContext::BYPASS => null,
            CompanyContext::COMPANY => $column.' = '.$context->requireCompanyId(),
            CompanyContext::PLATFORM => $kind === TenantTables::NULLABLE ? $column.' is null' : throw new MissingCompanyContext("Company table [$table] is not available to the platform administrator."),
            default => throw new MissingCompanyContext("Company table [$table] queried without a company context."),
        };
    }

    private function withCompany(mixed $from, array $values): array
    {
        if (! is_string($from) || ! $this->filters($from)) {
            return $values;
        }
        $context = $this->context();
        [$table] = TenantTables::parse($from);
        $nullable = TenantTables::kind($table) === TenantTables::NULLABLE;
        $records = $values === [] ? [[]] : (is_array(reset($values)) ? $values : [$values]);
        $mode = $context->mode();
        if ($mode === CompanyContext::PLATFORM && ! $nullable) {
            throw new MissingCompanyContext("Company table [$table] is not available to the platform administrator.");
        }
        if ($mode !== CompanyContext::COMPANY && $mode !== CompanyContext::PLATFORM) {
            throw new MissingCompanyContext("Insert into company table [$table] without a company context.");
        }
        $companyId = $context->companyId();
        foreach ($records as $i => $record) {
            if (array_key_exists('company_id', $record)) {
                $given = $record['company_id'];
                if (! ($given instanceof Expression) && ($given === null ? $companyId !== null : (int) $given !== $companyId)) {
                    throw new MissingCompanyContext("Insert into [$table] names a different company.");
                }

                continue;
            }
            // Platform rows (superadmins, their audit trail) keep company_id NULL.
            if ($companyId !== null) {
                $records[$i]['company_id'] = new Expression($companyId);
            }
        }

        return $records;
    }

    /** True when the table is company data and the current mode is not bypass. */
    private function filters(mixed $from): bool
    {
        if (! is_string($from) || $from === '') {
            return false;
        }

        return TenantTables::kind(TenantTables::parse($from)[0]) !== TenantTables::GLOBAL
            && $this->context()->mode() !== CompanyContext::BYPASS;
    }

    private function context(): CompanyContext
    {
        return Container::getInstance()->make(CompanyContext::class);
    }
}
