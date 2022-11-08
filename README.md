# hy-medoo
## Install (安装)
```shell
composer require sgenmi/hy-medoo

```

## Demo (例子)

```php

1. AdminModel.php

namespace App\Model;

use Sgenmi\HyMedoo\AbstractModel;

class AdminModel extends AbstractModel
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


# 数据模型：Model

```php
 //注： 采⽤开源medoo作为框架的model，兼容medoo语法
       不需要写表名，已进行二次封装，表名会自动带入
```
## Medoo 文档

官网文档： [https://medoo.in/](https://medoo.in/)

特别感谢Medoo作者，开源这么小巧 好用的类库 