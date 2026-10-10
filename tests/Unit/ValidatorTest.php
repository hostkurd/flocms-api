<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Controller;
use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Kernel;
use FloCMS\Api\Router;
use FloCMS\Api\Validation\ValidatesRequests;
use FloCMS\Api\Validation\Validator;
use FloCMS\Core\Http\Request;
use FloCMS\Core\ValidationException as CoreValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    use MakesRequests;

    /** @return iterable<string, array{0: string|list<string>, 1: mixed, 2: bool}> */
    public static function cases(): iterable
    {
        yield 'required ok' => ['required', 'x', true];
        yield 'required empty string' => ['required', '', false];
        yield 'required empty array' => ['required', [], false];
        yield 'string' => ['required|string', 5, false];
        yield 'integer string' => ['required|integer', '-12', true];
        yield 'integer float' => ['required|integer', '1.5', false];
        yield 'numeric' => ['required|numeric', '12.5', true];
        yield 'numeric junk' => ['required|numeric', 'abc', false];
        yield 'numeric min' => ['required|numeric|min:1000', '999', false];
        yield 'string min counts characters' => ['required|string|min:4', '0123', true];
        yield 'max utf-8 characters' => ['required|string|max:3', 'کور', true];
        yield 'array max counts items' => ['required|array|max:2', [1, 2, 3], false];
        yield 'between number' => ['required|integer|between:1,10', '10', true];
        yield 'between number out' => ['required|integer|between:1,10', '11', false];
        yield 'between length' => ['required|string|between:2,3', 'abcd', false];
        yield 'in' => ['required|in:sale,rent', 'rent', true];
        yield 'in strict' => ['required|in:0,1', '01', false];
        yield 'in array value' => ['required|in:a', ['a'], false];
        yield 'email' => ['required|email', 'sara@example.com', true];
        yield 'email bad' => ['required|email', 'sara@', false];
        yield 'url' => ['required|url', 'https://example.com/a?b=1', true];
        yield 'url scheme' => ['required|url', 'javascript://alert(1)', false];
        yield 'url ftp' => ['required|url', 'ftp://example.com', false];
        yield 'bool true string' => ['required|bool', 'true', true];
        yield 'bool zero' => ['required|bool', 0, true];
        yield 'bool yes' => ['required|bool', 'yes', false];
        yield 'array' => ['required|array', ['a'], true];
        yield 'date' => ['required|date', '2026-10-10', true];
        yield 'date invalid day' => ['required|date', '2026-02-30', false];
        yield 'date atom' => ['required|date', '2026-10-10T12:00:00+03:00', true];
        yield 'date format' => ['required|date:d/m/Y', '10/10/2026', true];
        yield 'date format mismatch' => ['required|date:d/m/Y', '2026-10-10', false];
        yield 'regex array form' => [['required', 'regex:/^[A-Z]{2}(\d+|X)$/'], 'KR12', true];
        yield 'regex mismatch' => [['required', 'regex:/^[A-Z]{2}\d+$/'], 'kr12', false];
    }

    /** @param string|list<string> $rules */
    #[DataProvider('cases')]
    public function testRules(string|array $rules, mixed $value, bool $passes): void
    {
        try {
            (new Validator())->validate(['field' => $value], ['field' => $rules]);
            self::assertTrue($passes, 'expected a validation error');
        } catch (ValidationException $error) {
            self::assertFalse($passes, json_encode($error->errors));
            self::assertSame(422, $error->status);
            self::assertIsString($error->errors['field'][0]);
        }
    }

    public function testValidatedSubsetIsTypedAndFiltered(): void
    {
        $data = (new Validator())->validate(
            ['page' => '2', 'price' => '12.5', 'featured' => 'false', 'note' => '', 'name' => 'Villa', 'is_admin' => '1'],
            [
                'page' => 'required|integer',
                'price' => 'numeric',
                'featured' => 'bool',
                'note' => 'nullable|string',
                'name' => 'required|string',
                'missing' => 'string',
                'gone' => 'sometimes|required',
            ]
        );

        self::assertSame(['page' => 2, 'price' => 12.5, 'featured' => false, 'note' => null, 'name' => 'Villa'], $data);
    }

    public function testRequiredNullableStillRequiresAValue(): void
    {
        $this->expectException(ValidationException::class);

        (new Validator())->validate(['a' => null], ['a' => 'required|nullable|string']);
    }

    public function testSometimesValidatesWhenPresent(): void
    {
        $this->expectException(ValidationException::class);

        (new Validator())->validate(['email' => 'nope'], ['email' => 'sometimes|email']);
    }

    public function testRequiredStopsFurtherMessagesAndCustomMessages(): void
    {
        try {
            (new Validator())->validate([], ['email' => 'required|email', 'name' => 'required'], [
                'email.required' => 'We need your email.',
                'required' => 'Please fill in :field.',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $error) {
            self::assertSame(['email' => ['We need your email.'], 'name' => ['Please fill in name.']], $error->errors);
        }
    }

    public function testCustomRule(): void
    {
        $validator = (new Validator())->extend('even', static fn (mixed $value): ?string => (int) $value % 2 === 0 ? null : 'Must be even.');

        self::assertSame(['n' => 4], $validator->validate(['n' => 4], ['n' => 'required|even']));
        $this->expectException(ValidationException::class);
        $validator->validate(['n' => 3], ['n' => 'required|even']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function developerErrors(): iterable
    {
        yield 'unknown rule' => ['required|frobnicate'];
        yield 'min without value' => ['required|min'];
        yield 'bad between' => ['required|between:1'];
        yield 'bad regex' => ['required|regex:/(/'];
        yield 'exists without column' => ['required|exists:users'];
        yield 'exists bad identifier' => ['required|exists:users;drop,id'];
    }

    #[DataProvider('developerErrors')]
    public function testRuleMistakesAreDeveloperErrors(string $rules): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Validator())->validate(['field' => 'x'], ['field' => $rules]);
    }

    public function testQueryValidationThroughControllerTrait(): void
    {
        $controller = new class extends Controller {
            use ValidatesRequests;

            /** @return array<string, mixed> */
            public function index(Request $request): array
            {
                return $this->validateQuery($request, ['min_price' => 'numeric|min:0', 'page' => 'integer|min:1']);
            }
        };
        $router = new Router();
        $router->get('/v1/properties', [$controller, 'index']);
        $kernel = new Kernel($router);

        $bad = $kernel->handle($this->request('GET', '/v1/properties?min_price=abc'));
        self::assertSame(422, $bad->status());
        self::assertArrayHasKey('min_price', $this->payload($bad)['errors']);

        $request = new Request(['min_price' => '1500', 'page' => '3'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/v1/properties'], [], []);
        self::assertSame(['min_price' => 1500, 'page' => 3], $this->payload($kernel->handle($request))['data']);
    }

    public function testCoreValidationExceptionConverts(): void
    {
        $converted = ValidationException::fromCore(new CoreValidationException(['a' => ['x']]));

        self::assertSame(422, $converted->status);
        self::assertSame(['a' => ['x']], $converted->errors);
    }
}
