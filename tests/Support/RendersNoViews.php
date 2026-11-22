<?php

namespace Tests\Support;

use Illuminate\Contracts\View\Engine;

/**
 * Makes every page of the panel render to nothing, so a test reads what a controller handed its view (`$response->viewData()`) and the statements it ran to get it, and not the
 * markup of the shell and its helpers. The view is still found and its composers still run; only the template is not compiled.
 */
trait RendersNoViews
{
    protected function renderNoViews(): void
    {
        $engine = new class implements Engine {
            public function get($path, array $data = []): string
            {
                return '';
            }
        };

        $resolver = $this->app->make('view.engine.resolver');
        $resolver->register('blade', static fn (): Engine => $engine);
        $resolver->register('php', static fn (): Engine => $engine);
    }
}
