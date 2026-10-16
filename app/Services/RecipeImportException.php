<?php

namespace App\Services;

use RuntimeException;

/** A recipe import failure whose message can be shown to the person importing. */
class RecipeImportException extends RuntimeException {}
