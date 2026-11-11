<section class="section contacts-section" id="contacts"><div class="container contact-layout">
  <div class="contact-details"><p class="eyebrow">{{ site_text('Мы рядом') }}</p><h2>{{ site_text('Встретимся в LEGIONAUTORENT') }}</h2>
    <a class="contact-phone" href="tel:<?php if(truth(dv('city.phone',get_defined_vars()))): ?>{{ df(dv('city.phone',get_defined_vars()),'phone_link') }}<?php else: ?>{{ df(dv('site.phone',get_defined_vars()),'phone_link') }}<?php endif; ?>" data-event="click_phone">{{ first_value(dv('city.phone',get_defined_vars()),dv('site.phone',get_defined_vars())) }}</a>
    <p><?php if(truth(dv('city',get_defined_vars()))): ?>{{ df(dv('city',get_defined_vars()),'tr','address') }}<?php else: ?>{{ df(dv('site',get_defined_vars()),'tr','address') }}<?php endif; ?></p>
    <?php if(truth(dv('city.hours',get_defined_vars()))): ?><p>{{ df(dv('city',get_defined_vars()),'tr','hours') }}</p><?php elseif(truth(dv('site.hours',get_defined_vars()))): ?><p>{{ df(dv('site',get_defined_vars()),'tr','hours') }}</p><?php endif; ?>
    <div class="contact-actions"><a class="text-link" href="{{ dv('site.whatsapp_url',get_defined_vars()) }}" target="_blank" rel="noopener noreferrer" data-event="click_whatsapp">{{ icon('whatsapp') }} WhatsApp ↗</a></div>
  </div>
  <div class="contact-map">
    <?php $map_url=map_embed(dv('city',get_defined_vars()),dv('site',get_defined_vars()));  ?><?php $external_map=map_link(dv('city',get_defined_vars()),dv('site',get_defined_vars()));  ?>
    <?php if(truth(dv('map_url',get_defined_vars()))): ?><div class="map-viewport"><iframe title="{{ site_text('Карта проезда к LEGIONAUTORENT') }}" src="{{ dv('map_url',get_defined_vars()) }}" width="640" height="400" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div><?php endif; ?>
    <div class="map-address"><div><strong><span class="brand-name">LEGIONAUTORENT</span></strong><p><?php if(truth(dv('city',get_defined_vars()))): ?>{{ first_value(localized($city,'address'),localized($city,'name')) }}<?php else: ?>{{ localized($site,'address') }}<?php endif; ?></p></div><a href="{{ dv('external_map',get_defined_vars()) }}" target="_blank" rel="noopener noreferrer">{{ site_text('Открыть карту') }}{{ icon('arrow') }}</a></div>
  </div>
</div></section>
