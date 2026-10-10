<?php
declare(strict_types=1);

namespace FloCMS\Api\Validation;

use FloCMS\Core\Http\Request;

/**
 * Validation helpers for API controllers:
 *
 *     final class PropertiesController extends Controller
 *     {
 *         use ValidatesRequests;
 *
 *         public function store(Request $request): Response
 *         {
 *             $data = $this->validate($request, ['title' => 'required|string|max:200']);
 *             ...
 *         }
 *     }
 *
 * A trait rather than base Controller methods, so controllers that already
 * have their own validate() keep working.
 */
trait ValidatesRequests
{
    /**
     * Validate the request input (query string, form fields, JSON body) and
     * return the validated subset; a failure is a 422 with per-field errors.
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        return $this->validator()->validateRequest($request, $rules, $messages);
    }

    /**
     * Validate the query string only, e.g. list filters.
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    protected function validateQuery(Request $request, array $rules, array $messages = []): array
    {
        return $this->validator()->validateQuery($request, $rules, $messages);
    }

    /** Override to add custom rules or use a specific connection. */
    protected function validator(): Validator
    {
        return new Validator();
    }
}
