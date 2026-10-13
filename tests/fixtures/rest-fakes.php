<?php

declare(strict_types=1);

/*
 * The two WordPress REST classes the controllers use, as small fakes: there's
 * no WordPress in the unit tests (Brain Monkey mocks functions, not classes).
 */

if (!class_exists('WP_REST_Request')) {
    /** @template T of array */
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private string $body = '', private array $params = [])
        {
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /** @return array<mixed>|null */
        public function get_json_params(): ?array
        {
            $data = json_decode($this->body, true);

            return is_array($data) ? $data : null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
