<?php

namespace CluebotNG;

/*
 * Copyright (C) 2025 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */

class Metrics
{
    private $logger = null;
    private $enabled = false;
    private $endpoint = null;
    private $instance_identifier;
    private $registry;
    private $metrics = array();

    function __construct($logger, $endpoint = null, $instance_identifier = null)
    {
        $this->logger = $logger;
        $this->endpoint = $endpoint;
        $this->instance_identifier = $instance_identifier ?: getmypid();
        $this->enabled = $this->endpoint != null;

        if ($this->enabled) {
            $this->setupMetricRegistry();
        } else {
            $logger->addDebug('Metrics are not enabled');
        }
    }

    private function setupMetricRegistry()
    {
        # Note: By full path rather than `use` so we can selectively require the `Prometheus` client package
        $this->registry = new \Prometheus\CollectorRegistry(new \Prometheus\Storage\InMemory(), false);

        $this->metrics["edits_processed"] = $this->registry->registerCounter(
            'cbng',
            'edits_processed',
            'Total number of edits send for processing from the feed'
        );

        $this->metrics["edits_missing_data"] = $this->registry->registerCounter(
            'cbng',
            'edits_missing_data',
            'Total number of edits missing data for processing'
        );

        $this->metrics["edits_below_threshold"] = $this->registry->registerCounter(
            'cbng',
            'edits_below_threshold',
            'Total number of edits below the threshold for vandalism'
        );

        $this->metrics["edits_user_whitelisted"] = $this->registry->registerCounter(
            'cbng',
            'edits_user_whitelisted',
            'Total number of edits with a whitelisted user'
        );

        $this->metrics["edits_reverted"] = $this->registry->registerCounter(
            'cbng',
            'edits_reverted',
            'Total number of reverted edits'
        );

        $this->metrics["edits_not_reverted"] = $this->registry->registerCounter(
            'cbng',
            'edits_not_reverted',
            'Total number of beaten edits'
        );

        $this->metrics["last_successfully_processed_edit"] = $this->registry->registerGauge(
            'cbng',
            'last_successfully_processed_edit',
            'Unix timestamp of last successfully processed edit'
        );

        $this->metrics["last_reverted_edit"] = $this->registry->registerGauge(
            'cbng',
            'last_reverted_edit',
            'Unix timestamp of last successfully reverted edit'
        );
    }

    public function pushMetrics($instance_identifier = null)
    {
        try {
            $pushGateway = new \PrometheusPushGateway\PushGateway($this->endpoint);
            $pushGateway->push($this->registry, 'cbng', ['instance' => $instance_identifier ?: $this->instance_identifier]);
        } catch (\Exception $e) {
            $this->logger->addError("Failed to push metrics: " . $e->getMessage());
        }
    }

    public function incrementCounter($metric, $incrementBy = 1, $values = array())
    {
        if (!$this->enabled) {
            return;
        }

        if (!array_key_exists($metric, $this->metrics)) {
            $this->logger->addWarning("No metric found for '$metric'");
            return;
        }
        $this->metrics[$metric]->incBy($incrementBy, $values);
    }

    public function setGauge($metric, $value, $values = array())
    {
        if (!$this->enabled) {
            return;
        }

        if (!array_key_exists($metric, $this->metrics)) {
            $this->logger->addWarning("No metric found for '$metric'");
            return;
        }
        $this->metrics[$metric]->set($value, $values);
    }
}
