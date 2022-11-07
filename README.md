# hy-medoo
## Install (安装)
```shell
composer require sgenmi/hy-medoo

```

## Demo (例子)

```php

1. AdminModel.php

namespace App\Model;

use Sgenmi\HyMedoo\HyMedoo;

class AdminModel extends HyMedoo
{
    protected string $table = 'admin';

    public function getList(): array
    {
        return $this->select('*', ['LIMIT' => 30]);
    }
}

2.IndexController.php


<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use App\Model\AdminModel;

class IndexController extends AbstractController
{
    public function index()
    {
        $model = new AdminModel();
        $list = $model->getList();
        $list2 = $model->select('*', ['LIMIT' => 10]);
        $info = $model->get('*', ['AND' => ['id' => 2]]);
        $info = $model->get(['id', 'username'], ['AND' => ['id' => 2]]);
        $res = $model->update(['status' => 0], ['id' => 2]);
        $res = $model->insert(['username' => 'sgenmi']);
        var_dump($res);
        return $list;
    }
}

```

