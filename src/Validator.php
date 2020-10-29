<?php

/*
 * This file is part of the awurth/slim-validation package.
 *
 * (c) Alexis Wurth <awurth.dev@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Awurth\SlimValidation;

use Psr\Http\Message\ServerRequestInterface as Request;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validatable as RespectValidatable;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * @author Alexis Wurth <awurth.dev@gmail.com>
 */
class Validator
{
    /**
     * The default error messages for the given rules.
     *
     * @var string[]
     */
    private $defaultMessages;

    /**
     * The list of validation errors.
     *
     * @var ValidationErrorList
     */
    private $errors;

    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    public function __construct(array $defaultMessages = [])
    {
        $this->defaultMessages = $defaultMessages;
    }

    /**
     * Validates an array with the given rules.
     *
     * @param array                      $array
     * @param RespectValidatable[]|array $rules
     * @param string[]                   $messages
     * @param mixed|null                 $default
     *
     * @return ValidationErrorList
     */
    public function validateArray(array $array, array $rules, array $messages = [], $default = null): ValidationErrorList
    {
        $this->errors = new ValidationErrorList();
        foreach ($rules as $key => $options) {
            $validatable = ValidatableFactory::create($key, $options, $default);
            $this->validateInput($array[$key] ?? $validatable->getDefault(), $validatable, $messages);
        }

        return $this->getErrorList();
    }

    /**
     * Validates an objects properties with the given rules.
     *
     * @param object                     $object
     * @param RespectValidatable[]|array $rules
     * @param string[]                   $messages
     * @param mixed|null                 $default
     *
     * @return ValidationErrorList
     */
    public function validateObject(object $object, array $rules, array $messages = [], $default = null): ValidationErrorList
    {
        $this->errors = new ValidationErrorList();
        foreach ($rules as $property => $options) {
            $validatable = ValidatableFactory::create($property, $options, $default);

            $input = $this->getPropertyAccessor()->isReadable($object, $property)
                ? $this->getPropertyAccessor()->getValue($object, $property)
                : $validatable->getDefault();

            $this->validateInput($input, $validatable, $messages);
        }

        return $this->getErrorList();
    }

    /**
     * Validates request parameters with the given rules.
     *
     * @param Request                    $request
     * @param RespectValidatable[]|array $rules
     * @param string[]                   $messages
     * @param mixed|null                 $default
     *
     * @return ValidationErrorList
     */
    public function validateRequest(Request $request, array $rules, array $messages = [], $default = null): ValidationErrorList
    {
        $this->errors = new ValidationErrorList();
        foreach ($rules as $param => $options) {
            $validatable = ValidatableFactory::create($param, $options, $default);
            $this->validateInput(
                $this->getRequestParam($request, $param, $validatable->getDefault()),
                $validatable,
                $messages
            );
        }

        return $this->getErrorList();
    }

    /**
     * Validates a single value with the given rules.
     *
     * @param mixed                    $input
     * @param RespectValidatable|array $rules
     * @param string                   $path
     * @param string[]                 $messages
     *
     * @return ValidationErrorList
     */
    public function validate($input, $rules, string $path, array $messages = []): ValidationErrorList
    {
        $this->errors = new ValidationErrorList();
        $this->validateInput($input, ValidatableFactory::create($path, $rules), $messages);

        return $this->getErrorList();
    }

    public function getDefaultMessage(string $name): string
    {
        return $this->defaultMessages[$name] ?? '';
    }

    public function getDefaultMessages(): array
    {
        return $this->defaultMessages;
    }

    public function setDefaultMessage(string $name, string $message): self
    {
        $this->defaultMessages[$name] = $message;

        return $this;
    }

    public function setDefaultMessages(array $messages): self
    {
        $this->defaultMessages = $messages;

        return $this;
    }

    private function extractMessagesFromException(NestedValidationException $exception, Validatable $validatable, array $messages = []): array
    {
        $definedMessages = array_replace($this->defaultMessages, $messages, $validatable->getMessages());

        $errors = [];
        foreach ($exception->getMessages($definedMessages) as $name => $error) {
            if (is_array($error)) {
                array_replace($errors, $error);
            } else {
                $errors[$name] = $error;
            }
        }

        return $errors;
    }

    /**
     * Fetches a request parameter's value from the body or query string (in that order).
     *
     * @param Request     $request
     * @param string      $key
     * @param string|null $default
     *
     * @return mixed
     */
    private function getRequestParam(Request $request, $key, $default = null)
    {
        $postParams = $request->getParsedBody();
        $getParams = $request->getQueryParams();
        $route = $request->getAttribute('route');
        $routeParams = [];

        if (class_exists(RouteContext::class)) {
            $routeContext = RouteContext::fromRequest($request);
            $route = $routeContext->getRoute();
        }

        if ($route instanceof RouteInterface) {
            $routeParams = $route->getArguments();
        }

        $result = $default;
        if (is_array($postParams) && isset($postParams[$key])) {
            $result = $postParams[$key];
        } elseif (is_object($postParams) && property_exists($postParams, $key)) {
            $result = $postParams->$key;
        } elseif (isset($getParams[$key])) {
            $result = $getParams[$key];
        } elseif (isset($routeParams[$key])) {
            $result = $routeParams[$key];
        } elseif (isset($_FILES[$key])) {
            $result = $_FILES[$key];
        }

        return $result;
    }

    private function handleValidationException(NestedValidationException $e, Validatable $validatable, array $messages, $input): void
    {
        if ($message = $validatable->getMessage()) {
            $this->getErrorList()->add(new ValidationError($validatable->getPath(), $message, $input));
        } else {
            $exceptionMessages = $this->extractMessagesFromException($e, $validatable, $messages);
            foreach ($exceptionMessages as $name => $message) {
                $this->getErrorList()->add(
                    (new ValidationError($validatable->getPath(), $message, $input))->setName($name)
                );
            }
        }
    }

    private function validateInput($input, Validatable $validatable, array $messages = []): void
    {
        try {
            $validatable->getValidationRules()->assert($input);
        } catch (NestedValidationException $e) {
            $this->handleValidationException($e, $validatable, $messages, $input);
        }
    }

    private function getErrorList(): ValidationErrorList
    {
        if (null === $this->errors) {
            $this->errors = new ValidationErrorList();
        }

        return $this->errors;
    }

    private function getPropertyAccessor(): PropertyAccessor
    {
        if (null === $this->propertyAccessor) {
            $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        }

        return $this->propertyAccessor;
    }
}
