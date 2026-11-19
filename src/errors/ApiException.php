<?php

namespace justinholtweb\yarn\errors;

use RuntimeException;

/**
 * A request to Yarn's API that cannot be answered as asked — an unknown site, check or element.
 *
 * The message is written for whoever made the request, so the MCP tools pass it on as a tool
 * error and the GraphQL queries as a `UserError`, word for word.
 */
class ApiException extends RuntimeException
{
}
