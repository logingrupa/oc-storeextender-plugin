<h4 class="my-3 fw-normal"><?= e(Lang::get('lovata.ordersshopaholic::lang.menu.orders')) ?></h4>
<?= $this->relationRender('order', ['readOnly' => true]) ?>
