protected $middlewareAliases = [
    'auth.firebase' => \App\Http\Middleware\CheckFirebaseAuth::class,
    'role' => \App\Http\Middleware\RoleMiddleware::class,
];