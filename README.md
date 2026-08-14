# scsq-waas-coupon

MU-plugin: coupon riservato ai clienti WaaS su **scalesquad.ai**. Al checkout valida
`WAAS-XXXXXXXX` contro il Core GAW e applica lo sconto agli agenti Pro (product_cat
`abbonamenti`). Uso singolo, fail-safe, host-guard su scalesquad.ai.

**Deploy**: Plesk Git su scalesquad.ai (cs01) → `/httpdocs/wp-content/mu-plugins/`.
Il file a root del repo È il mu-plugin (subpath deploy pulito, come il tema).
