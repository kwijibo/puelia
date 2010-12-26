
<h2>
    <a href="<?php echo $topic->docUri ?>"><?php echo $topic->label ?></a>
</h2>

    <?php if ($topic->img): ?>
        <div class="depiction">        
            <img alt="<?php echo $topic->img()->label ?>" src="<?php echo $topic->img ?>" width="200">
        </div>
    <?php endif ?>

<p class="description">
    <?php echo $topic->description ?>
</p>

    <?php if ($topic->isMappable): ?>
        <div class="map topic-map">
            <span class="lat" title="Latitiude"><?php echo $topic->latitude ?></span>
            <span class="long" title="Longitude"><?php echo $topic->longitude ?></span>
        </div>
    <?php endif ?>