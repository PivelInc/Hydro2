<?php

namespace Package\CCMS\Controllers;

use Exception;
use Package\CCMS\Extensions\Route;
use Package\CCMS\Extensions\RoutePrefix;
use Package\CCMS\Models\HTTP\Method;
use Package\CCMS\Utilities;
use ReflectionClass;

class Router
{
    private array $routes;

    public function __construct() {

    }

    public function RegisterRoutesFromAttributes() : void {
        // Get list of controllers from Utilities\getPackageManifest()
        $controllers = [];
        $pkg_manifest = Utilities::getPackageManifest();
        foreach ($pkg_manifest as $pkg_name => $pkg_info) {
            if (!isset($pkg_info['controllers'])) {
                continue;
            }

            foreach ($pkg_info['controllers'] as $c) {
                $controllers[] = $c;//::class;
            }
        }

        // Build list of routes
        $this->routes = [];
        foreach ($controllers as $c) {

            $class = new ReflectionClass($c);

            $route_prefix_segments = [];
            $class_attributes = $class->getAttributes(RoutePrefix::class);
            foreach ($class_attributes as $class_attribute) {
                $route_prefix = $class_attribute->newInstance();
                $prefix = $route_prefix->pathPrefix;
                $prefix = trim($prefix, "/");
                $route_prefix_segments = explode("/", $prefix);
            }

            foreach ($class->getMethods() as $method) {
                $attributes = $method->getAttributes(Route::class);

                foreach ($attributes as $attribute) {
                    $route = $attribute->newInstance();
                    $use_prefix = !str_starts_with($route->path, '~');
                    if (!$use_prefix) {
                        $route->path = substr($route->path, 1);
                    }
                    $path = trim($route->path, '/');
                    $route_segments = explode('/', $path);
                    $this->routes[] = [
                        'method' => $route->method,
                        'path' => ($use_prefix ? array_merge($route_prefix_segments, $route_segments) : $route_segments),
                        'controller_class' => $c,
                        'controller_method' => $method->getName(),
                        'order' => $route->order,
                    ];
                }
            }
        }

        // Sort routes
        $this->SortRoutes();
    }

    public function LoadRoutes() : bool {
        if (!file_exists(dirname(__FILE__, 2) . '/routes.json')) {
            return false;
        }

        //$loading_start = microtime(true);
        $raw_routes = file_get_contents(dirname(__FILE__, 2) . '/routes.json');
        //$loading_end = microtime(true);
        //echo "Took " . ($loading_end - $loading_start) * 1000 . 'ms to load file contents.';
        $this->routes = json_decode($raw_routes, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            $this->routes = [];
            return false;
        }

        return true;
    }

    public function SaveRoutes() : void {
        file_put_contents(dirname(__FILE__, 2) . '/routes.json', json_encode($this->routes));
    }

    public function GetMatchingRoutes(Method $method, string $path) : array {
        $path_segments = explode('/', trim($path, '/'));
        $matching_routes = array_values(array_filter($this->routes, function($route) use ($method, $path_segments) {
            //echo "Comparing " . join('/', $path_segments) . ' to template ' . join('/', $route['path']) . ':';
            $route_method = $route['method'];
            if (!($route_method instanceof Method)) {
                $route_method = Method::tryFrom($route_method) ?? Method::GET;
            }
            if ($method != $route_method) {
                //echo "Method " . $method->value . " didn't match " . $route['method'] . "<br />";
                return false;
            }

            $template_segment_idx = 0;
            $wildcard_matched = false;
            for ($i = 0; $i < count($path_segments); $i++) {
                if (!isset($route['path'][$template_segment_idx])) {
                    //echo 'Not enough template segments<br />';
                    return false;
                }

                $template_segment = $route['path'][$template_segment_idx];
                $template_segment_type = self::GetPathSegmentType($template_segment);

                if ($template_segment_type == self::SEG_LITERAL) {
                    if ($path_segments[$i] != $template_segment) {
                        //echo 'Literal segment ' . $path_segments[$i] . ' didn\'t match template\'s ' . $template_segment . '<br />';
                        return false;
                    }

                    $template_segment_idx++;
                    continue;
                }

                if ($template_segment_type == self::SEG_PARAM_CONSTRAINED) {
                    // check against constraint. if no match, return false
                    // otherwise, increment $template_segment_idx and continue.
                }

                if ($template_segment_type == self::SEG_PARAM_UNCONSTRAINED) {
                    // will always match.
                    $template_segment_idx++;
                    continue;
                }

                if ($template_segment_type == self::SEG_WILD_PARAM_CONSTRAINED) {
                    // must match at least once.
                    // somehow, check against constraint.
                    // however, if next template segment is a literal that matches the current segment and
                    // we've already matched this wildcard at least once, match that template segment.
                    // increment template_segment_idx twice, and continue.
                    if (!$wildcard_matched) {
                        $wildcard_matched = true;
                        continue;
                    }

                    if (!isset($route['path'][$template_segment_idx+1])) {
                        continue;
                    }

                    $next_template_segment = $route['path'][$template_segment_idx+1];
                    $next_template_segment_type = self::GetPathSegmentType($next_template_segment);
                    if ($next_template_segment_type == self::SEG_LITERAL && $next_template_segment == $path_segments[$i]) {
                        $template_segment_idx += 2;
                        $wildcard_matched = false;
                    }
                }

                if ($template_segment_type == self::SEG_WILD_PARAM_UNCONSTRAINED) {
                    // must match at least once.
                    // however, if next template segment is a literal that matches the current segment and
                    // we've already matched this wildcard at least once, match that template segment.
                    // increment template_segment_idx twice, and continue.
                    if (!$wildcard_matched) {
                        $wildcard_matched = true;
                        continue;
                    }

                    if (!isset($route['path'][$template_segment_idx+1])) {
                        continue;
                    }

                    $next_template_segment = $route['path'][$template_segment_idx+1];
                    $next_template_segment_type = self::GetPathSegmentType($next_template_segment);
                    if ($next_template_segment_type == self::SEG_LITERAL && $next_template_segment == $path_segments[$i]) {
                        $template_segment_idx += 2;
                        $wildcard_matched = false;
                    }
                }
            }

            // If we've made it through:
            // If we are in a wildcard, continue to the next segment
            if ($wildcard_matched) {
                $template_segment_idx++;
            }
            // If there are segments left in the template, return false
            if ($template_segment_idx < count($route['path'])) {
                //echo 'Still unmatched template segments left over<br />';
                return false;
            }

            // Otherwise,
            //echo 'Matched!<br />';
            return true;
        }));

        return $matching_routes;
    }

    /**
     * Sorts the routing table as follows:
     *  1. Compare order property (lower gets evaluated first)
     *  2. If order matches, compare segment types in order:
     *      a) literal ("/segment/")
     *      b) contrained parameter ("/{param:int}/")
     *      c) unconstrained parameter ("/{param}/")
     *      d) contrained wildcard parameter ("/{*param:date}/")
     *      e) unconstrained wildcard parameter ("/{*param}/")
     *  3. If still a tie, compare template string (without leading or trailing '/' or leading '~')
     */

    private function SortRoutes() : void {
        usort($this->routes, [self::class, 'RouteCmp']);
    }

    public static function RouteCmp($a, $b) {
        // 1.
        if ($a['order'] != $b['order']) {
            return $a['order'] <=> $b['order'];
        }

        // 2.
        for ($i = 0; $i < min(count($a['path']), count($b['path'])); $i++){
            $a_type = self::GetPathSegmentType($a['path'][$i]);
            $b_type = self::GetPathSegmentType($b['path'][$i]);
            if ($a_type != $b_type) {
                return $a_type <=> $b_type;
            }
        }

        // 3.
        // Leading/trailing '/' and leading '~' were already stripped when bulding the routing table.
        return strcmp(join('/', $a['path']), join('/', $b['path']));
    }

    const SEG_LITERAL = 0;
    const SEG_PARAM_CONSTRAINED = 1;
    const SEG_PARAM_UNCONSTRAINED = 2;
    const SEG_WILD_PARAM_CONSTRAINED = 3;
    const SEG_WILD_PARAM_UNCONSTRAINED = 4;

    public static function GetPathSegmentType(string $segment) : int {
        if (str_starts_with($segment, '{*') && str_ends_with($segment, '}')) {
            // Wildcard parameter. Add check for constraints later.
            return self::SEG_WILD_PARAM_UNCONSTRAINED;
        }

        if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
            // Parameter. Add check for constraints later.
            return self::SEG_PARAM_UNCONSTRAINED;
        }

        return self::SEG_LITERAL;
    }

    public static function ParsePathParameters(array $template, string $path) : array {
        $parameters = [];

        $path_segments = explode('/', $path);

        $template_segment_idx = 0;
        $wildcard_matched = false;
        for ($i = 0; $i < count($path_segments); $i++) {
            if (!isset($template[$template_segment_idx])) {
                throw new Exception('Template doesn\'t match path.');
            }

            $template_segment = $template[$template_segment_idx];
            $template_segment_type = self::GetPathSegmentType($template_segment);

            if ($template_segment_type == self::SEG_LITERAL) {
                $template_segment_idx++;
                continue;
            }

            if ($template_segment_type == self::SEG_PARAM_CONSTRAINED) {
                // check against constraint. if no match, return false.
                // otherwise, increment $template_segment_idx and continue.
            }

            if ($template_segment_type == self::SEG_PARAM_UNCONSTRAINED) {
                // will always match
                $parameter_name = substr($template_segment, 1, -1);
                $parameters[$parameter_name] = $path_segments[$i];
                $template_segment_idx++;
                continue;
            }

            if ($template_segment_type == self::SEG_WILD_PARAM_CONSTRAINED) {
                // must match at least once.
                // somehow, check against constraint.
                // however, if next template segment is a literal that matches the current segment and
                // we've already matched this wildcard at least once, match that template segment.
                // increment template_segment_idx twice, and continue.
            }

            if ($template_segment_type == self::SEG_WILD_PARAM_UNCONSTRAINED) {
                // must match at least once.
                // however, if next template segment is a literal that matches the current segment and
                // we've already matched this wildcard at least once, match that template segment.
                // increment template_segment_idx twice, and continue.
                $parameter_name = substr($template_segment, 2, -1);

                if (!$wildcard_matched) {
                    $wildcard_matched = true;
                    $parameters[$parameter_name] = $path_segments[$i];
                    continue;
                }

                if (!isset($route['path'][$template_segment_idx+1])) {
                    $parameters[$parameter_name] .= '/' . $path_segments[$i];
                    continue;
                }

                $next_template_segment = $route['path'][$template_segment_idx+1];
                $next_template_segment_type = self::GetPathSegmentType($next_template_segment);
                if ($next_template_segment_type == self::SEG_LITERAL && $next_template_segment == $path_segments[$i]) {
                    $template_segment_idx += 2;
                    $wildcard_matched = false;
                    continue;
                }

                $parameters[$parameter_name] .= '/' . $path_segments[$i];
            }
        }

        return $parameters;
    }
}