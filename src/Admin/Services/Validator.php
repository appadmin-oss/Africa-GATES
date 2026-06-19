<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Respect\Validation\Validator as v;

/**
 * Thin wrapper over Respect/Validation.
 * Returns ['field' => 'message'] error map.
 */
class Validator
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,callable(v):v> $rules
     * @return array{ok:bool, errors:array<string,string>}
     */
    public function check(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $build) {
            try {
                $validator = $build(v::create());
                $validator->setName(ucfirst(str_replace('_', ' ', $field)))->assert($data[$field] ?? null);
            } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
                $msgs = $e->getMessages();
                $errors[$field] = reset($msgs) ?: 'Invalid value';
            } catch (\Throwable $e) {
                $errors[$field] = $e->getMessage();
            }
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }
}
