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
    }

    function __destruct()
    {
        if ($this->enabled) {
            $this->pushMetrics();
        }
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
}
