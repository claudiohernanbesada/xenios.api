# Inventario técnico

Índice de todas las funciones PHP. Consultar FUNCIONALIDADES.md para reglas de negocio y API.md para rutas. Las firmas enlazan al cuerpo real, incluidos campos delegados y estructuras anidadas.

## _inc/api_tokens.php

| Función | Firma |
|---|---|
| [xenios_api_token_esperado](../_inc/api_tokens.php#L5) | `function xenios_api_token_esperado(): string` |
| [xenios_api_token_recibido](../_inc/api_tokens.php#L12) | `function xenios_api_token_recibido(): string` |
| [xenios_validar_api_token](../_inc/api_tokens.php#L24) | `function xenios_validar_api_token(): bool` |

## _inc/env_loader.php

| Función | Firma |
|---|---|
| [xenios_cargar_env](../_inc/env_loader.php#L3) | `function xenios_cargar_env(?string $path = null): void` |

## _inc/rutas_archivos.php

| Función | Firma |
|---|---|
| [xenios_dir_absoluta_api_reg](../_inc/rutas_archivos.php#L17) | `function xenios_dir_absoluta_api_reg(string $carpeta, ?string $envVar = null): ?string` |
| [xenios_rutas_archivos](../_inc/rutas_archivos.php#L93) | `function xenios_rutas_archivos(): array` |
| [xenios_api_site_root](../_inc/rutas_archivos.php#L103) | `function xenios_api_site_root(): string` |
| [xenios_document_root](../_inc/rutas_archivos.php#L113) | `function xenios_document_root(): string` |
| [xenios_url_es_sitio_api](../_inc/rutas_archivos.php#L123) | `function xenios_url_es_sitio_api(string $baseUrl): bool` |
| [xenios_url_usa_https](../_inc/rutas_archivos.php#L135) | `function xenios_url_usa_https(string $baseUrl): bool` |
| [xenios_ruta_fisica](../_inc/rutas_archivos.php#L147) | `function xenios_ruta_fisica(string $clave): string` |
| [xenios_ruta_url](../_inc/rutas_archivos.php#L173) | `function xenios_ruta_url(string $clave): string` |
| [xenios_rutas_respaldo](../_inc/rutas_archivos.php#L199) | `function xenios_rutas_respaldo(string $clave): array` |
| [xenios_url_archivo](../_inc/rutas_archivos.php#L239) | `function xenios_url_archivo(string $clave, string $nombreArchivo): string` |
| [xenios_ruta_fisica_relativa_api](../_inc/rutas_archivos.php#L249) | `function xenios_ruta_fisica_relativa_api(string $clave): string` |
| [xenios_ruta_fisica_escritura](../_inc/rutas_archivos.php#L273) | `function xenios_ruta_fisica_escritura(string $clave): string` |
| [xenios_ruta_legacy_api_xenios](../_inc/rutas_archivos.php#L307) | `function xenios_ruta_legacy_api_xenios(string $clave): ?string` |
| [xenios_rutas_fisicas_candidatas](../_inc/rutas_archivos.php#L335) | `function xenios_rutas_fisicas_candidatas(string $clave): array` |
| [xenios_path_archivo_local](../_inc/rutas_archivos.php#L385) | `function xenios_path_archivo_local(string $clave, string $nombreArchivo): ?string` |
| [xenios_asegurar_directorio](../_inc/rutas_archivos.php#L415) | `function xenios_asegurar_directorio(string $clave): string` |

## _inc/security.php

| Función | Firma |
|---|---|
| [xenios_security_headers](../_inc/security.php#L5) | `function xenios_security_headers(): void` |
| [xenios_require_service_key](../_inc/security.php#L15) | `function xenios_require_service_key(): void` |
| [xenios_rate_limit](../_inc/security.php#L28) | `function xenios_rate_limit(string $scope, int $limit, int $seconds): void` |

## _inc/stream_archivo.php

| Función | Firma |
|---|---|
| [xenios_archivo_mime](../_inc/stream_archivo.php#L8) | `function xenios_archivo_mime(string $fileName): string` |
| [xenios_archivo_es_pdf](../_inc/stream_archivo.php#L21) | `function xenios_archivo_es_pdf(string $bytes): bool` |
| [xenios_archivo_es_imagen](../_inc/stream_archivo.php#L27) | `function xenios_archivo_es_imagen(string $bytes): bool` |
| [xenios_archivo_urls_respaldo](../_inc/stream_archivo.php#L42) | `function xenios_archivo_urls_respaldo(string $clave, string $nombreArchivo): array` |
| [xenios_fetch_archivo_remoto](../_inc/stream_archivo.php#L59) | `function xenios_fetch_archivo_remoto(string $clave, string $nombreArchivo, ?callable $validar = null): ?string` |
| [xenios_archivo_send](../_inc/stream_archivo.php#L99) | `function xenios_archivo_send(string $fileName, string $bytes, string $disposition = 'inline'): void` |
| [xenios_stream_archivo](../_inc/stream_archivo.php#L112) | `function xenios_stream_archivo(string $clave, string $nombreArchivo, array $opciones = []): bool` |

## actions/alicuotas/update.php

| Función | Firma |
|---|---|
| [responder](../actions/alicuotas/update.php#L10) | `function responder(int $status, array $payload): void` |
| [pdo_connect](../actions/alicuotas/update.php#L17) | `function pdo_connect(string $servidor, string $baseDeDatos, string $usuario, string $contrasena): PDO` |
| [normalizar_cuit](../actions/alicuotas/update.php#L26) | `function normalizar_cuit(?string $cuit): string` |
| [decimal_o_null](../actions/alicuotas/update.php#L31) | `function decimal_o_null($value): ?float` |
| [env_o_null](../actions/alicuotas/update.php#L41) | `function env_o_null(string $key): ?string` |
| [fecha_vigencia_desde](../actions/alicuotas/update.php#L47) | `function fecha_vigencia_desde(): string` |
| [fecha_vigencia_hasta](../actions/alicuotas/update.php#L52) | `function fecha_vigencia_hasta(): ?string` |
| [http_json_get](../actions/alicuotas/update.php#L57) | `function http_json_get(string $url, array $query = [], array $headers = [], int $timeout = 25): ?array` |
| [valor_decimal_por_claves](../actions/alicuotas/update.php#L86) | `function valor_decimal_por_claves(array $data, array $keys): ?float` |
| [consultar_alicuota_iibb_arba](../actions/alicuotas/update.php#L109) | `function consultar_alicuota_iibb_arba(string $cuit): array` |
| [consultar_alicuota_ganancias_afip](../actions/alicuotas/update.php#L149) | `function consultar_alicuota_ganancias_afip(string $cuit): array` |
| [consultar_alicuotas](../actions/alicuotas/update.php#L189) | `function consultar_alicuotas(array $empresa): ?array` |
| [upsert_alicuota](../actions/alicuotas/update.php#L260) | `function upsert_alicuota(PDO $pdo, array $empresa, array $alicuotas): void` |
| [guid](../actions/alicuotas/update.php#L295) | `function guid(): string` |

## xenios-movil/config.php

| Función | Firma |
|---|---|
| [xenios_movil_first_existing_file](../xenios-movil/config.php#L12) | `function xenios_movil_first_existing_file(array $paths): string` |
| [xenios_movil_dotenv_value](../xenios-movil/config.php#L26) | `function xenios_movil_dotenv_value(string $key, array $paths): string` |
| [xenios_movil_env_int](../xenios-movil/config.php#L64) | `function xenios_movil_env_int(string $key, int $default): int` |
| [xenios_movil_env_value](../xenios-movil/config.php#L83) | `function xenios_movil_env_value(string $key, array $paths, string $default = ''): string` |

## xenios-movil/index.php

| Función | Firma |
|---|---|
| [handle_app_version](../xenios-movil/index.php#L120) | `function handle_app_version(PDO $pdo, array $config): void` |
| [compare_app_versions](../xenios-movil/index.php#L144) | `function compare_app_versions(string $left, string $right): int` |
| [handle_horas_extra_motivo_pendientes_mail](../xenios-movil/index.php#L164) | `function handle_horas_extra_motivo_pendientes_mail(PDO $pdo, array $config): void` |
| [normalize_app_version](../xenios-movil/index.php#L187) | `function normalize_app_version(string $version): array` |
| [fetch_google_play_version](../xenios-movil/index.php#L197) | `function fetch_google_play_version(string $storeUrl): string` |
| [handle_recuperar_clave_solicitud](../xenios-movil/index.php#L230) | `function handle_recuperar_clave_solicitud(PDO $pdo, array $config): void` |
| [handle_recuperar_clave_validar](../xenios-movil/index.php#L246) | `function handle_recuperar_clave_validar(PDO $pdo, array $config): void` |
| [handle_recuperar_clave_completar](../xenios-movil/index.php#L259) | `function handle_recuperar_clave_completar(PDO $pdo, array $config): void` |
| [handle_login](../xenios-movil/index.php#L276) | `function handle_login(PDO $pdo, array $config): void` |
| [handle_doble_factor](../xenios-movil/index.php#L326) | `function handle_doble_factor(PDO $pdo, array $config): void` |
| [completar_login_movil](../xenios-movil/index.php#L359) | `function completar_login_movil(PDO $pdo, array $config, array $user, ?string $mfaVersion = null): void` |
| [handle_logout](../xenios-movil/index.php#L390) | `function handle_logout(PDO $pdo, array $config): void` |
| [handle_me](../xenios-movil/index.php#L399) | `function handle_me(PDO $pdo, array $config): void` |
| [handle_cambiar_clave](../xenios-movil/index.php#L414) | `function handle_cambiar_clave(PDO $pdo, array $config): void` |
| [registrar_log_xenios](../xenios-movil/index.php#L460) | `function registrar_log_xenios(PDO $pdo, string $usuarioId, string $accion, string $detalle, string $plataforma): void` |
| [cliente_ip_xenios](../xenios-movil/index.php#L475) | `function cliente_ip_xenios(): string` |
| [handle_avatar_upload](../xenios-movil/index.php#L490) | `function handle_avatar_upload(PDO $pdo, array $config): void` |
| [handle_avatar_imagen](../xenios-movil/index.php#L545) | `function handle_avatar_imagen(PDO $pdo, array $config): void` |
| [handle_avatar_delete](../xenios-movil/index.php#L561) | `function handle_avatar_delete(PDO $pdo, array $config): void` |
| [handle_fichadas](../xenios-movil/index.php#L585) | `function handle_fichadas(PDO $pdo, array $config): void` |
| [handle_autorizaciones](../xenios-movil/index.php#L635) | `function handle_autorizaciones(PDO $pdo, array $config): void` |
| [handle_autorizacion_archivo](../xenios-movil/index.php#L652) | `function handle_autorizacion_archivo(PDO $pdo, array $config, string $path, string $method): void` |
| [handle_asistencia](../xenios-movil/index.php#L693) | `function handle_asistencia(PDO $pdo, array $config): void` |
| [handle_vacaciones](../xenios-movil/index.php#L734) | `function handle_vacaciones(PDO $pdo, array $config): void` |
| [novedades_require_tables](../xenios-movil/index.php#L770) | `function novedades_require_tables(PDO $pdo, array $config): void` |
| [handle_push_registrar_token](../xenios-movil/index.php#L787) | `function handle_push_registrar_token(PDO $pdo, array $config): void` |
| [handle_push_fichadas_procesar](../xenios-movil/index.php#L817) | `function handle_push_fichadas_procesar(PDO $pdo, array $config): void` |
| [handle_push_fichadas_evento](../xenios-movil/index.php#L841) | `function handle_push_fichadas_evento(PDO $pdo, array $config): void` |
| [handle_usuarios_list](../xenios-movil/index.php#L863) | `function handle_usuarios_list(PDO $pdo, array $config): void` |
| [handle_areas_list](../xenios-movil/index.php#L878) | `function handle_areas_list(PDO $pdo, array $config): void` |
| [handle_reporte_asistencia_xenios](../xenios-movil/index.php#L893) | `function handle_reporte_asistencia_xenios(PDO $pdo, array $config): void` |
| [handle_reporte_asistencia_detallada_xenios](../xenios-movil/index.php#L921) | `function handle_reporte_asistencia_detallada_xenios(PDO $pdo, array $config): void` |
| [handle_reporte_horas_trabajadas_xenios](../xenios-movil/index.php#L949) | `function handle_reporte_horas_trabajadas_xenios(PDO $pdo, array $config): void` |
| [handle_novedades_list](../xenios-movil/index.php#L977) | `function handle_novedades_list(PDO $pdo, array $config): void` |
| [handle_novedades_enviar](../xenios-movil/index.php#L1002) | `function handle_novedades_enviar(PDO $pdo, array $config): void` |
| [handle_novedades_routes](../xenios-movil/index.php#L1053) | `function handle_novedades_routes(PDO $pdo, array $config, string $path, string $method): void` |
| [handle_reporte_horas_extras_diarias_xenios](../xenios-movil/index.php#L1083) | `function handle_reporte_horas_extras_diarias_xenios(PDO $pdo, array $config): void` |

## xenios-movil/lib/areas.php

| Función | Firma |
|---|---|
| [areas_listar_activas](../xenios-movil/lib/areas.php#L5) | `function areas_listar_activas(PDO $pdo): array` |

## xenios-movil/lib/asistencia_calendario.php

| Función | Firma |
|---|---|
| [asistencia_build_calendario](../xenios-movil/lib/asistencia_calendario.php#L13) | `function asistencia_build_calendario(PDO $pdo, array $personal, string $desde, string $hasta): array` |
| [asistencia_armar_dia](../xenios-movil/lib/asistencia_calendario.php#L74) | `function asistencia_armar_dia(` |
| [asistencia_cargar_turnos](../xenios-movil/lib/asistencia_calendario.php#L194) | `function asistencia_cargar_turnos(PDO $pdo): array` |
| [asistencia_cargar_fichadas](../xenios-movil/lib/asistencia_calendario.php#L211) | `function asistencia_cargar_fichadas(` |
| [asistencia_cargar_auth_salida](../xenios-movil/lib/asistencia_calendario.php#L246) | `function asistencia_cargar_auth_salida(` |
| [asistencia_cargar_licencias_por_dia](../xenios-movil/lib/asistencia_calendario.php#L273) | `function asistencia_cargar_licencias_por_dia(` |
| [asistencia_cargar_feriados](../xenios-movil/lib/asistencia_calendario.php#L321) | `function asistencia_cargar_feriados(` |
| [asistencia_agrupar_fichadas_por_dia](../xenios-movil/lib/asistencia_calendario.php#L348) | `function asistencia_agrupar_fichadas_por_dia(` |
| [asistencia_referencia_limite_posterior_auth](../xenios-movil/lib/asistencia_calendario.php#L462) | `function asistencia_referencia_limite_posterior_auth(` |
| [asistencia_clasificar_fichadas_por_turno](../xenios-movil/lib/asistencia_calendario.php#L500) | `function asistencia_clasificar_fichadas_por_turno(` |
| [asistencia_es_fichada_mas_cercana_a_salida](../xenios-movil/lib/asistencia_calendario.php#L558) | `function asistencia_es_fichada_mas_cercana_a_salida(` |
| [asistencia_get_turno_salida](../xenios-movil/lib/asistencia_calendario.php#L631) | `function asistencia_get_turno_salida(` |
| [asistencia_obtener_horario_evaluacion](../xenios-movil/lib/asistencia_calendario.php#L673) | `function asistencia_obtener_horario_evaluacion(array $turno, bool $esViernes): array` |
| [asistencia_restar_una_hora](../xenios-movil/lib/asistencia_calendario.php#L686) | `function asistencia_restar_una_hora(string $time): string` |
| [asistencia_time_to_minutes](../xenios-movil/lib/asistencia_calendario.php#L696) | `function asistencia_time_to_minutes(string $time): float` |
| [asistencia_normalizar_hora](../xenios-movil/lib/asistencia_calendario.php#L702) | `function asistencia_normalizar_hora(mixed $time): ?string` |
| [asistencia_es_horario_nocturno](../xenios-movil/lib/asistencia_calendario.php#L715) | `function asistencia_es_horario_nocturno(?string $horarioIngreso, ?string $horarioSalida): bool` |
| [asistencia_obtener_fecha_laboral_fichada](../xenios-movil/lib/asistencia_calendario.php#L723) | `function asistencia_obtener_fecha_laboral_fichada(` |
| [asistencia_parse_fecha_baja](../xenios-movil/lib/asistencia_calendario.php#L731) | `function asistencia_parse_fecha_baja(mixed $raw): ?DateTimeImmutable` |

## xenios-movil/lib/auth.php

| Función | Firma |
|---|---|
| [sha256_password](../xenios-movil/lib/auth.php#L9) | `function sha256_password(string $plain): string` |
| [validate_password_policy](../xenios-movil/lib/auth.php#L15) | `function validate_password_policy(string $password): ?string` |
| [issue_token](../xenios-movil/lib/auth.php#L36) | `function issue_token(string $userId, string $mail, string $secret, int $ttl, ?string $mfaVersion = null): string` |
| [verify_token](../xenios-movil/lib/auth.php#L55) | `function verify_token(string $token, string $secret): ?array` |
| [bearer_token](../xenios-movil/lib/auth.php#L79) | `function bearer_token(): ?string` |
| [user_has_any_role](../xenios-movil/lib/auth.php#L114) | `function user_has_any_role(array $roles): bool` |
| [require_any_role](../xenios-movil/lib/auth.php#L125) | `function require_any_role(array $roles, array $config): void` |
| [user_has_empleado_role](../xenios-movil/lib/auth.php#L136) | `function user_has_empleado_role(array $roles): bool` |
| [require_empleado_role](../xenios-movil/lib/auth.php#L147) | `function require_empleado_role(array $roles, array $config): void` |
| [require_gestion_role](../xenios-movil/lib/auth.php#L159) | `function require_gestion_role(array $roles, array $config): void` |
| [empleado_may_write_avatar](../xenios-movil/lib/auth.php#L174) | `function empleado_may_write_avatar(string $path, string $method): bool` |
| [empleado_may_post](../xenios-movil/lib/auth.php#L183) | `function empleado_may_post(string $path): bool` |
| [enforce_method_for_roles](../xenios-movil/lib/auth.php#L193) | `function enforce_method_for_roles(array $roles, string $method, string $path, array $config): void` |
| [current_api_path](../xenios-movil/lib/auth.php#L217) | `function current_api_path(array $config): string` |
| [fetch_user_roles](../xenios-movil/lib/auth.php#L233) | `function fetch_user_roles(PDO $pdo, string $userId): array` |
| [fetch_personal_by_mail](../xenios-movil/lib/auth.php#L245) | `function fetch_personal_by_mail(PDO $pdo, string $mail): ?array` |
| [fetch_personal_empleado_completo](../xenios-movil/lib/auth.php#L262) | `function fetch_personal_empleado_completo(PDO $pdo, string $mail): ?array` |
| [build_usuario_response](../xenios-movil/lib/auth.php#L283) | `function build_usuario_response(array $user, array $roles, ?array $personal, array $config): array` |
| [normalizar_legajo_claves](../xenios-movil/lib/auth.php#L302) | `function normalizar_legajo_claves(int $legajo): array` |
| [config_api_key](../xenios-movil/lib/auth.php#L309) | `function config_api_key(array $config): string` |
| [validar_api_key](../xenios-movil/lib/auth.php#L316) | `function validar_api_key(array $config): bool` |
| [require_api_key](../xenios-movil/lib/auth.php#L337) | `function require_api_key(array $config): void` |
| [require_auth](../xenios-movil/lib/auth.php#L344) | `function require_auth(PDO $pdo, array $config): array` |
| [require_auth_or_web_bridge](../xenios-movil/lib/auth.php#L398) | `function require_auth_or_web_bridge(PDO $pdo, array $config): array` |

## xenios-movil/lib/auth_salida_daily_report.php

| Función | Firma |
|---|---|
| [auth_salida_daily_report_config](../xenios-movil/lib/auth_salida_daily_report.php#L7) | `function auth_salida_daily_report_config(array $config): array` |
| [auth_salida_daily_report_recipients](../xenios-movil/lib/auth_salida_daily_report.php#L22) | `function auth_salida_daily_report_recipients(PDO $pdo): array` |
| [auth_salida_daily_report_rows](../xenios-movil/lib/auth_salida_daily_report.php#L55) | `function auth_salida_daily_report_rows(PDO $pdo, DateTimeImmutable $date): array` |
| [auth_salida_daily_report_license_rows](../xenios-movil/lib/auth_salida_daily_report.php#L93) | `function auth_salida_daily_report_license_rows(PDO $pdo, DateTimeImmutable $date): array` |
| [auth_salida_daily_report_subject](../xenios-movil/lib/auth_salida_daily_report.php#L123) | `function auth_salida_daily_report_subject(DateTimeImmutable $date): string` |
| [auth_salida_daily_report_text_body](../xenios-movil/lib/auth_salida_daily_report.php#L133) | `function auth_salida_daily_report_text_body(array $authRows, array $licenseRows): string` |
| [auth_salida_daily_report_html_body](../xenios-movil/lib/auth_salida_daily_report.php#L187) | `function auth_salida_daily_report_html_body(array $authRows, array $licenseRows, ?string $logoCid): string` |
| [auth_salida_daily_report_send](../xenios-movil/lib/auth_salida_daily_report.php#L266) | `function auth_salida_daily_report_send(PDO $pdo, array $config, DateTimeImmutable $date): array` |
| [auth_salida_daily_report_send_smtp](../xenios-movil/lib/auth_salida_daily_report.php#L322) | `function auth_salida_daily_report_send_smtp(` |
| [auth_salida_daily_report_mime_message](../xenios-movil/lib/auth_salida_daily_report.php#L392) | `function auth_salida_daily_report_mime_message(` |
| [auth_salida_daily_report_th](../xenios-movil/lib/auth_salida_daily_report.php#L445) | `function auth_salida_daily_report_th(string $text): string` |
| [auth_salida_daily_report_td](../xenios-movil/lib/auth_salida_daily_report.php#L452) | `function auth_salida_daily_report_td(string $text): string` |
| [auth_salida_daily_report_empty_row](../xenios-movil/lib/auth_salida_daily_report.php#L459) | `function auth_salida_daily_report_empty_row(int $colspan, string $text): string` |
| [auth_salida_daily_report_format_date](../xenios-movil/lib/auth_salida_daily_report.php#L466) | `function auth_salida_daily_report_format_date(string $value): string` |
| [auth_salida_daily_report_format_time](../xenios-movil/lib/auth_salida_daily_report.php#L472) | `function auth_salida_daily_report_format_time(string $value): string` |
| [auth_salida_daily_report_format_datetime](../xenios-movil/lib/auth_salida_daily_report.php#L477) | `function auth_salida_daily_report_format_datetime(string $value): string` |

## xenios-movil/lib/auth_salida_file.php

| Función | Firma |
|---|---|
| [stream_auth_salida_file](../xenios-movil/lib/auth_salida_file.php#L9) | `function stream_auth_salida_file(string $authFile, array $config): bool` |

## xenios-movil/lib/auth_salida_list.php

| Función | Firma |
|---|---|
| [auth_salida_list_for_personal](../xenios-movil/lib/auth_salida_list.php#L14) | `function auth_salida_list_for_personal(PDO $pdo, array $personal): array` |
| [auth_salida_format_list_item](../xenios-movil/lib/auth_salida_list.php#L48) | `function auth_salida_format_list_item(array $row, array $turnos, string $areaNombre, array $personal): array` |
| [auth_salida_format_datetime_hora](../xenios-movil/lib/auth_salida_list.php#L79) | `function auth_salida_format_datetime_hora(?string $value): ?string` |
| [auth_salida_resolve_regreso_efectiva](../xenios-movil/lib/auth_salida_list.php#L99) | `function auth_salida_resolve_regreso_efectiva(array $row, array $turnos, string $areaNombre, array $personal): array` |
| [auth_salida_datetime_from_date_time](../xenios-movil/lib/auth_salida_list.php#L153) | `function auth_salida_datetime_from_date_time(DateTimeImmutable $fecha, string $hora): DateTimeImmutable` |

## xenios-movil/lib/auth_salida_scan.php

| Función | Firma |
|---|---|
| [user_has_role_name](../xenios-movil/lib/auth_salida_scan.php#L8) | `function user_has_role_name(array $roles, string $roleName): bool` |
| [require_auth_salida_scan_role](../xenios-movil/lib/auth_salida_scan.php#L19) | `function require_auth_salida_scan_role(array $roles, array $config): void` |
| [auth_salida_scan_client_ip](../xenios-movil/lib/auth_salida_scan.php#L28) | `function auth_salida_scan_client_ip(): string` |
| [auth_salida_scan_extract_id](../xenios-movil/lib/auth_salida_scan.php#L46) | `function auth_salida_scan_extract_id(mixed $raw): string` |
| [auth_salida_scan_invalid](../xenios-movil/lib/auth_salida_scan.php#L72) | `function auth_salida_scan_invalid(array $config): void` |
| [handle_auth_salida_scan](../xenios-movil/lib/auth_salida_scan.php#L77) | `function handle_auth_salida_scan(PDO $pdo, array $config): void` |

## xenios-movil/lib/avatar.php

| Función | Firma |
|---|---|
| [delete_archivo_fisico](../xenios-movil/lib/avatar.php#L16) | `function delete_archivo_fisico(string $claveRepositorio, ?string $nombreArchivo): void` |
| [delete_personal_foto_file](../xenios-movil/lib/avatar.php#L28) | `function delete_personal_foto_file(?string $foto, array $config): void` |
| [ensure_upload_directory](../xenios-movil/lib/avatar.php#L34) | `function ensure_upload_directory(string $claveRepositorio): string` |
| [save_uploaded_foto](../xenios-movil/lib/avatar.php#L40) | `function save_uploaded_foto(array $file, string $claveRepositorio, ?int $maxBytes = null): ?string` |
| [resolve_url_archivo_publico](../xenios-movil/lib/avatar.php#L77) | `function resolve_url_archivo_publico(string $claveRepositorio, string $fileName): string` |
| [resolve_avatar_url](../xenios-movil/lib/avatar.php#L93) | `function resolve_avatar_url(array $user, array $config): ?string` |
| [stream_repositorio_imagen](../xenios-movil/lib/avatar.php#L104) | `function stream_repositorio_imagen(string $claveRepositorio, string $fileName): bool` |
| [stream_avatar_for_user](../xenios-movil/lib/avatar.php#L112) | `function stream_avatar_for_user(array $user): bool` |

## xenios-movil/lib/db.php

| Función | Firma |
|---|---|
| [db_connect](../xenios-movil/lib/db.php#L6) | `function db_connect(array $config): PDO` |

## xenios-movil/lib/doble_factor.php

| Función | Firma |
|---|---|
| [doble_factor_error](../xenios-movil/lib/doble_factor.php#L5) | `function doble_factor_error(array $result, string $fallback): array` |
| [doble_factor_config](../xenios-movil/lib/doble_factor.php#L13) | `function doble_factor_config(array $config): array` |
| [doble_factor_solicitar](../xenios-movil/lib/doble_factor.php#L29) | `function doble_factor_solicitar(array $config, string $accion, array $datos): array` |
| [doble_factor_firma](../xenios-movil/lib/doble_factor.php#L48) | `function doble_factor_firma(string $key, string $canal, string $id, string $timestamp, string $version): string` |
| [doble_factor_token_valido](../xenios-movil/lib/doble_factor.php#L53) | `function doble_factor_token_valido(array $user, array $payload): bool` |
| [doble_factor_prueba_valida](../xenios-movil/lib/doble_factor.php#L61) | `function doble_factor_prueba_valida(array $config, array $proof): bool` |
| [doble_factor_puente_web_valido](../xenios-movil/lib/doble_factor.php#L71) | `function doble_factor_puente_web_valido(array $config, array $user, string $timestamp): bool` |

## xenios-movil/lib/fichadas_push.php

| Función | Firma |
|---|---|
| [fichadas_push_ensure_log_table](../xenios-movil/lib/fichadas_push.php#L5) | `function fichadas_push_ensure_log_table(PDO $pdo): void` |
| [fichadas_push_ensure_log_column](../xenios-movil/lib/fichadas_push.php#L36) | `function fichadas_push_ensure_log_column(PDO $pdo, string $column, string $definition): void` |
| [fichadas_push_has_token_table](../xenios-movil/lib/fichadas_push.php#L53) | `function fichadas_push_has_token_table(PDO $pdo): bool` |
| [fichadas_push_procesar](../xenios-movil/lib/fichadas_push.php#L67) | `function fichadas_push_procesar(` |
| [fichadas_push_insert_logs](../xenios-movil/lib/fichadas_push.php#L180) | `function fichadas_push_insert_logs(PDO $pdo, array $logKeys): void` |
| [fichadas_push_guardar_resultados_envio](../xenios-movil/lib/fichadas_push.php#L199) | `function fichadas_push_guardar_resultados_envio(PDO $pdo, array $logKeys, array $providerResults): void` |
| [fichadas_push_registrar_evento_usuario](../xenios-movil/lib/fichadas_push.php#L248) | `function fichadas_push_registrar_evento_usuario(PDO $pdo, string $usuarioId, int $fichadaId, string $evento): bool` |

## xenios-movil/lib/horas_extra_motivo_pendientes.php

| Función | Firma |
|---|---|
| [horas_extra_motivo_pendientes_enviar](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L11) | `function horas_extra_motivo_pendientes_enviar(PDO $pdo, array $config): array` |
| [horas_extra_motivo_pendientes_por_area](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L86) | `function horas_extra_motivo_pendientes_por_area(PDO $pdo, array $config, DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [horas_extra_motivo_calcular_diarias](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L103) | `function horas_extra_motivo_calcular_diarias(PDO $pdo, array $config, DateTimeImmutable $desde, DateTimeImmutable $hasta, ?int $tipoContratacionId = null, array $areaIds = []): array` |
| [horas_extra_motivo_diarias_empleado](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L144) | `function horas_extra_motivo_diarias_empleado(array $periodo, array $datos, array $empleado, string $areaId): array` |
| [hemp_fila_diaria](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L233) | `function hemp_fila_diaria(array $empleado, string $areaId, string $fecha): array` |
| [horas_extra_motivo_esta_tipificada](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L245) | `function horas_extra_motivo_esta_tipificada(PDO $pdo, string $cacheKey, string $personalId, string $fecha): bool` |
| [horas_extra_motivo_usuarios_destinatarios](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L265) | `function horas_extra_motivo_usuarios_destinatarios(PDO $pdo): array` |
| [horas_extra_motivo_mail_html](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L301) | `function horas_extra_motivo_mail_html(array $mailConfig, string $nombre, array $areas, DateTimeImmutable $desde, DateTimeImmutable $hasta): string` |
| [horas_extra_motivo_mail_texto](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L348) | `function horas_extra_motivo_mail_texto(string $nombre, array $areas, DateTimeImmutable $desde, DateTimeImmutable $hasta): string` |
| [horas_extra_motivo_send_html_mail](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L365) | `function horas_extra_motivo_send_html_mail(array $mailConfig, string $toMail, string $subject, string $html, string $text): array` |
| [horas_extra_motivo_mime_message](../xenios-movil/lib/horas_extra_motivo_pendientes.php#L419) | `function horas_extra_motivo_mime_message(array $mailConfig, string $toMail, string $from, string $fromName, string $subject, string $html, string $text): string` |

## xenios-movil/lib/horas_trabajadas_empleado.php

| Función | Firma |
|---|---|
| [horas_trabajadas_build_resumen](../xenios-movil/lib/horas_trabajadas_empleado.php#L25) | `function horas_trabajadas_build_resumen(PDO $pdo, array $personal, string $desde, string $hasta, array $config = []): array` |
| [ht_asistencia_calculo_config](../xenios-movil/lib/horas_trabajadas_empleado.php#L40) | `function ht_asistencia_calculo_config(array $config): array` |
| [horas_trabajadas_enriquecer_personal](../xenios-movil/lib/horas_trabajadas_empleado.php#L55) | `function horas_trabajadas_enriquecer_personal(PDO $pdo, array $personal): array` |
| [horas_trabajadas_normalizar_periodo](../xenios-movil/lib/horas_trabajadas_empleado.php#L98) | `function horas_trabajadas_normalizar_periodo(DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [horas_trabajadas_cargar_datos](../xenios-movil/lib/horas_trabajadas_empleado.php#L116) | `function horas_trabajadas_cargar_datos(PDO $pdo, array $empleado, array $periodo): array` |
| [horas_trabajadas_licencias_rangos](../xenios-movil/lib/horas_trabajadas_empleado.php#L214) | `function horas_trabajadas_licencias_rangos(` |
| [horas_trabajadas_procesar_empleado](../xenios-movil/lib/horas_trabajadas_empleado.php#L278) | `function horas_trabajadas_procesar_empleado(array $periodo, array $datos, array $empleado): array` |
| [horas_trabajadas_armar_respuesta](../xenios-movil/lib/horas_trabajadas_empleado.php#L466) | `function horas_trabajadas_armar_respuesta(` |
| [horas_trabajadas_resumen_vacio](../xenios-movil/lib/horas_trabajadas_empleado.php#L522) | `function horas_trabajadas_resumen_vacio(array $empleado): array` |
| [horas_trabajadas_calcular_minutos_auth](../xenios-movil/lib/horas_trabajadas_empleado.php#L528) | `function horas_trabajadas_calcular_minutos_auth(array $empleado, array $datos): float` |
| [horas_trabajadas_calcular_extras](../xenios-movil/lib/horas_trabajadas_empleado.php#L578) | `function horas_trabajadas_calcular_extras(` |
| [ht_procesar_grupo_dia_fichadas](../xenios-movil/lib/horas_trabajadas_empleado.php#L662) | `function ht_procesar_grupo_dia_fichadas(` |
| [ht_auths_dia](../xenios-movil/lib/horas_trabajadas_empleado.php#L833) | `function ht_auths_dia(array $datos, string $personalId, string $fechaKey): array` |
| [ht_inicio_auth_posterior](../xenios-movil/lib/horas_trabajadas_empleado.php#L839) | `function ht_inicio_auth_posterior(array $authsDia, DateTimeImmutable $entrada): ?DateTimeImmutable` |
| [ht_minutos_cubiertos_por_auth_hasta_fin_turno](../xenios-movil/lib/horas_trabajadas_empleado.php#L856) | `function ht_minutos_cubiertos_por_auth_hasta_fin_turno(` |
| [ht_sumar_intervalos_minutos](../xenios-movil/lib/horas_trabajadas_empleado.php#L904) | `function ht_sumar_intervalos_minutos(array $intervalos): float` |
| [ht_segundos_del_dia](../xenios-movil/lib/horas_trabajadas_empleado.php#L933) | `function ht_segundos_del_dia(DateTimeImmutable $dt): int` |
| [ht_obtener_entrada_salida_real_dia](../xenios-movil/lib/horas_trabajadas_empleado.php#L938) | `function ht_obtener_entrada_salida_real_dia(array $fichadasEmp, DateTimeImmutable $fecha): array` |
| [ht_tiene_fichada_incompleta](../xenios-movil/lib/horas_trabajadas_empleado.php#L965) | `function ht_tiene_fichada_incompleta(` |
| [ht_calcular_licencia](../xenios-movil/lib/horas_trabajadas_empleado.php#L1026) | `function ht_calcular_licencia(` |
| [ht_calcular_dias_vacaciones](../xenios-movil/lib/horas_trabajadas_empleado.php#L1059) | `function ht_calcular_dias_vacaciones(` |
| [ht_descuento_registro_personal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1072) | `function ht_descuento_registro_personal(float $minutos): int` |
| [ht_sumar_minutos_nominales](../xenios-movil/lib/horas_trabajadas_empleado.php#L1077) | `function ht_sumar_minutos_nominales(` |
| [ht_sumar_minutos_feriados_no_trabajados](../xenios-movil/lib/horas_trabajadas_empleado.php#L1107) | `function ht_sumar_minutos_feriados_no_trabajados(` |
| [ht_contar_dias_habiles](../xenios-movil/lib/horas_trabajadas_empleado.php#L1128) | `function ht_contar_dias_habiles(DateTimeImmutable $desde, DateTimeImmutable $hasta): int` |
| [ht_minutos_horas_trabajadas_dia_laboral](../xenios-movil/lib/horas_trabajadas_empleado.php#L1140) | `function ht_minutos_horas_trabajadas_dia_laboral(` |
| [ht_agregar_feriados_habiles_periodo](../xenios-movil/lib/horas_trabajadas_empleado.php#L1161) | `function ht_agregar_feriados_habiles_periodo(` |
| [ht_agregar_feriados_fin_de_semana_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1179) | `function ht_agregar_feriados_fin_de_semana_quincenal(` |
| [ht_quitar_feriados_habiles_cubiertos_por_licencia](../xenios-movil/lib/horas_trabajadas_empleado.php#L1211) | `function ht_quitar_feriados_habiles_cubiertos_por_licencia(` |
| [ht_agregar_feriados_habiles_no_trabajados_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1229) | `function ht_agregar_feriados_habiles_no_trabajados_quincenal(` |
| [ht_dia_excluido_por_licencia_formal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1259) | `function ht_dia_excluido_por_licencia_formal(` |
| [ht_minutos_feriados_habiles_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1276) | `function ht_minutos_feriados_habiles_quincenal(` |
| [ht_minutos_extras50_feriados_fin_de_semana_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1309) | `function ht_minutos_extras50_feriados_fin_de_semana_quincenal(` |
| [ht_aplicar_tope_horas_extras_por_dia](../xenios-movil/lib/horas_trabajadas_empleado.php#L1340) | `function ht_aplicar_tope_horas_extras_por_dia(array $datos, float &$extra50, float &$extra100, float &$compensable): void` |
| [ht_acumular_extras_manuales](../xenios-movil/lib/horas_trabajadas_empleado.php#L1357) | `function ht_acumular_extras_manuales(string $personalId, array $extrasPorPersonal, float &$e50, float &$e100): void` |
| [ht_acumular_extras_segun_franja](../xenios-movil/lib/horas_trabajadas_empleado.php#L1370) | `function ht_acumular_extras_segun_franja(` |
| [ht_acumular_extra_por_horario](../xenios-movil/lib/horas_trabajadas_empleado.php#L1402) | `function ht_acumular_extra_por_horario(` |
| [ht_acumular_tiempo_compensable](../xenios-movil/lib/horas_trabajadas_empleado.php#L1430) | `function ht_acumular_tiempo_compensable(` |
| [ht_minutos_sin_segundos](../xenios-movil/lib/horas_trabajadas_empleado.php#L1439) | `function ht_minutos_sin_segundos(DateTimeImmutable $inicio, DateTimeImmutable $fin): float` |
| [ht_redondear_hora_extra_completa](../xenios-movil/lib/horas_trabajadas_empleado.php#L1449) | `function ht_redondear_hora_extra_completa(float $minutos): int` |
| [ht_redondear_bloque](../xenios-movil/lib/horas_trabajadas_empleado.php#L1454) | `function ht_redondear_bloque(float $minutos, int $bloqueMinutos): int` |
| [ht_redondear_auth_minutos](../xenios-movil/lib/horas_trabajadas_empleado.php#L1463) | `function ht_redondear_auth_minutos(int $minutos): int` |
| [ht_parse_horario_empleado](../xenios-movil/lib/horas_trabajadas_empleado.php#L1471) | `function ht_parse_horario_empleado(?string $horario): ?string` |
| [ht_obtener_turno_asignado](../xenios-movil/lib/horas_trabajadas_empleado.php#L1486) | `function ht_obtener_turno_asignado(` |
| [ht_fecha_con_hora](../xenios-movil/lib/horas_trabajadas_empleado.php#L1536) | `function ht_fecha_con_hora(DateTimeImmutable $fecha, string $hora): DateTimeImmutable` |
| [ht_obtener_regreso_por_fin_de_turno](../xenios-movil/lib/horas_trabajadas_empleado.php#L1545) | `function ht_obtener_regreso_por_fin_de_turno(` |
| [ht_obtener_horario_evaluacion_turno](../xenios-movil/lib/horas_trabajadas_empleado.php#L1602) | `function ht_obtener_horario_evaluacion_turno(array $turno, bool $esViernes): array` |
| [ht_restar_una_hora_time](../xenios-movil/lib/horas_trabajadas_empleado.php#L1616) | `function ht_restar_una_hora_time(string $time): string` |
| [ht_es_turno_logistica](../xenios-movil/lib/horas_trabajadas_empleado.php#L1624) | `function ht_es_turno_logistica(array $turno): bool` |
| [ht_es_area_logistica](../xenios-movil/lib/horas_trabajadas_empleado.php#L1630) | `function ht_es_area_logistica(string $areaNombre): bool` |
| [ht_es_area_vigilancia](../xenios-movil/lib/horas_trabajadas_empleado.php#L1639) | `function ht_es_area_vigilancia(string $areaNombre): bool` |
| [ht_es_mensual_o_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1648) | `function ht_es_mensual_o_quincenal(string $nombre): bool` |
| [ht_es_quincenal](../xenios-movil/lib/horas_trabajadas_empleado.php#L1654) | `function ht_es_quincenal(string $nombre): bool` |
| [ht_es_horario_nocturno](../xenios-movil/lib/horas_trabajadas_empleado.php#L1659) | `function ht_es_horario_nocturno(?string $horarioIngreso, ?string $horarioSalida): bool` |
| [ht_obtener_fecha_laboral_fichada](../xenios-movil/lib/horas_trabajadas_empleado.php#L1667) | `function ht_obtener_fecha_laboral_fichada(` |
| [ht_time_to_seconds](../xenios-movil/lib/horas_trabajadas_empleado.php#L1675) | `function ht_time_to_seconds(string $time): int` |
| [ht_format_fecha_baja](../xenios-movil/lib/horas_trabajadas_empleado.php#L1681) | `function ht_format_fecha_baja(mixed $raw): ?string` |

## xenios-movil/lib/inout_seguridad.php

| Función | Firma |
|---|---|
| [inout_text_length](../xenios-movil/lib/inout_seguridad.php#L9) | `function inout_text_length(string $value): int` |
| [inout_text_cut](../xenios-movil/lib/inout_seguridad.php#L15) | `function inout_text_cut(string $value, int $maxBytes): string` |
| [inout_horario_futuro](../xenios-movil/lib/inout_seguridad.php#L23) | `function inout_horario_futuro(string $horario, ?DateTimeImmutable $ahora = null): bool` |
| [inout_uuid](../xenios-movil/lib/inout_seguridad.php#L29) | `function inout_uuid(): string` |
| [inout_error](../xenios-movil/lib/inout_seguridad.php#L38) | `function inout_error(string $message, array $config, int $status = 422): never` |
| [inout_patente](../xenios-movil/lib/inout_seguridad.php#L44) | `function inout_patente(mixed $value, array $config): ?string` |
| [inout_patentes](../xenios-movil/lib/inout_seguridad.php#L54) | `function inout_patentes(mixed $values, array $ids, array $config): array` |
| [inout_sede](../xenios-movil/lib/inout_seguridad.php#L66) | `function inout_sede(PDO $pdo, array $user, array $config): string` |
| [inout_require_permission](../xenios-movil/lib/inout_seguridad.php#L76) | `function inout_require_permission(array $auth, array $config): void` |
| [inout_notification](../xenios-movil/lib/inout_seguridad.php#L84) | `function inout_notification(array $rows): array` |
| [inout_build_push_messages](../xenios-movil/lib/inout_seguridad.php#L109) | `function inout_build_push_messages(array $tokens, array $notification): array` |
| [inout_guardar_destinatarios](../xenios-movil/lib/inout_seguridad.php#L130) | `function inout_guardar_destinatarios(PDO $pdo, string $registroId, array $usuarios): void` |
| [inout_destinatarios_registro](../xenios-movil/lib/inout_seguridad.php#L137) | `function inout_destinatarios_registro(PDO $pdo, array $registro): array` |
| [inout_notificaciones_usuario](../xenios-movil/lib/inout_seguridad.php#L147) | `function inout_notificaciones_usuario(PDO $pdo, string $uid, ?string $transaccion = null): array` |
| [inout_destinatarios_pendientes](../xenios-movil/lib/inout_seguridad.php#L161) | `function inout_destinatarios_pendientes(PDO $pdo, ?string $desde = null, int $segundos = 60): array` |
| [inout_send_notification](../xenios-movil/lib/inout_seguridad.php#L179) | `function inout_send_notification(PDO $pdo, string $transaccionId, ?string $destinatario = null): array` |
| [inout_presentes](../xenios-movil/lib/inout_seguridad.php#L215) | `function inout_presentes(PDO $pdo, string $sede, string $tipo, string $query = '', ?array $ids = null): array` |
| [inout_egreso_desde_ingresos](../xenios-movil/lib/inout_seguridad.php#L242) | `function inout_egreso_desde_ingresos(PDO $pdo, array $config, array $b, string $sede, string $uid): void` |
| [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L291) | `function handle_inout_seguridad(PDO $pdo, array $config, string $path, string $method): void` |

## xenios-movil/lib/notas_traslado.php

| Función | Firma |
|---|---|
| [notas_traslado_tipo_envio](../xenios-movil/lib/notas_traslado.php#L20) | `function notas_traslado_tipo_envio(array $nota): string` |
| [notas_traslado_capitalizar_palabras](../xenios-movil/lib/notas_traslado.php#L28) | `function notas_traslado_capitalizar_palabras(mixed $valor): string` |
| [notas_traslado_es_empleado](../xenios-movil/lib/notas_traslado.php#L42) | `function notas_traslado_es_empleado(array $nota): bool` |
| [notas_traslado_es_externo](../xenios-movil/lib/notas_traslado.php#L47) | `function notas_traslado_es_externo(array $nota): bool` |
| [notas_traslado_es_interno](../xenios-movil/lib/notas_traslado.php#L52) | `function notas_traslado_es_interno(array $nota): bool` |
| [notas_traslado_requiere_autorizacion](../xenios-movil/lib/notas_traslado.php#L57) | `function notas_traslado_requiere_autorizacion(array $nota): bool` |
| [notas_traslado_tiene_autorizacion_valida](../xenios-movil/lib/notas_traslado.php#L64) | `function notas_traslado_tiene_autorizacion_valida(array $nota): bool` |
| [notas_traslado_has_role](../xenios-movil/lib/notas_traslado.php#L70) | `function notas_traslado_has_role(array $roles, string $roleName): bool` |
| [notas_traslado_require_role](../xenios-movil/lib/notas_traslado.php#L85) | `function notas_traslado_require_role(array $roles, array $config): void` |
| [notas_traslado_require_auth](../xenios-movil/lib/notas_traslado.php#L110) | `function notas_traslado_require_auth(PDO $pdo, array $config): array` |
| [notas_traslado_is_admin](../xenios-movil/lib/notas_traslado.php#L119) | `function notas_traslado_is_admin(array $roles): bool` |
| [notas_traslado_normalizar_mail](../xenios-movil/lib/notas_traslado.php#L124) | `function notas_traslado_normalizar_mail(mixed $mail): string` |
| [notas_traslado_es_receptor](../xenios-movil/lib/notas_traslado.php#L129) | `function notas_traslado_es_receptor(array $nota, string $usuarioId, string $usuarioMail): bool` |
| [notas_traslado_estado_pendiente_despacho](../xenios-movil/lib/notas_traslado.php#L141) | `function notas_traslado_estado_pendiente_despacho(int $status): bool` |
| [notas_traslado_involucra_deposito](../xenios-movil/lib/notas_traslado.php#L152) | `function notas_traslado_involucra_deposito(array $nota, string $depositoId): bool` |
| [notas_traslado_normalizar_dni](../xenios-movil/lib/notas_traslado.php#L165) | `function notas_traslado_normalizar_dni(mixed $dni): string` |
| [notas_traslado_extract_id](../xenios-movil/lib/notas_traslado.php#L170) | `function notas_traslado_extract_id(mixed $raw): string` |
| [notas_traslado_status_label](../xenios-movil/lib/notas_traslado.php#L198) | `function notas_traslado_status_label(int $status): string` |
| [notas_traslado_pdf_escape](../xenios-movil/lib/notas_traslado.php#L214) | `function notas_traslado_pdf_escape(string $text): string` |
| [notas_traslado_pdf_wrap](../xenios-movil/lib/notas_traslado.php#L227) | `function notas_traslado_pdf_wrap(string $text, int $maxChars = 86): array` |
| [notas_traslado_pdf_add_line](../xenios-movil/lib/notas_traslado.php#L251) | `function notas_traslado_pdf_add_line(array &$pages, string &$content, float &$y, string $text, int $size = 10, bool $bold = false, float $x = 40): void` |
| [notas_traslado_pdf_text](../xenios-movil/lib/notas_traslado.php#L264) | `function notas_traslado_pdf_text(string &$content, float $x, float $y, string $text, int $size = 10, bool $bold = false, string $rgb = '0 0 0'): void` |
| [notas_traslado_pdf_text_width](../xenios-movil/lib/notas_traslado.php#L278) | `function notas_traslado_pdf_text_width(string $text, int $size, bool $bold = false): float` |
| [notas_traslado_pdf_text_right](../xenios-movil/lib/notas_traslado.php#L303) | `function notas_traslado_pdf_text_right(string &$content, float $right, float $y, string $text, int $size = 10, bool $bold = false, string $rgb = '0 0 0'): void` |
| [notas_traslado_pdf_rect](../xenios-movil/lib/notas_traslado.php#L309) | `function notas_traslado_pdf_rect(string &$content, float $x, float $y, float $w, float $h, string $stroke = '0.82 0.84 0.86', string $fill = ''): void` |
| [notas_traslado_pdf_line](../xenios-movil/lib/notas_traslado.php#L318) | `function notas_traslado_pdf_line(string &$content, float $x1, float $y1, float $x2, float $y2, string $stroke = '0.82 0.84 0.86'): void` |
| [notas_traslado_first_existing_file](../xenios-movil/lib/notas_traslado.php#L323) | `function notas_traslado_first_existing_file(array $paths): ?string` |
| [notas_traslado_pdf_jpeg_resource](../xenios-movil/lib/notas_traslado.php#L335) | `function notas_traslado_pdf_jpeg_resource(string $name, ?string $path): ?array` |
| [notas_traslado_pdf_image](../xenios-movil/lib/notas_traslado.php#L359) | `function notas_traslado_pdf_image(string &$content, string $name, float $x, float $y, float $w, float $h): void` |
| [notas_traslado_pdf_circle](../xenios-movil/lib/notas_traslado.php#L364) | `function notas_traslado_pdf_circle(string &$content, float $centerX, float $centerY, float $radius, string $fill = '1 1 1'): void` |
| [notas_traslado_qr_gf_tables](../xenios-movil/lib/notas_traslado.php#L399) | `function notas_traslado_qr_gf_tables(): array` |
| [notas_traslado_qr_gf_mul](../xenios-movil/lib/notas_traslado.php#L424) | `function notas_traslado_qr_gf_mul(int $a, int $b): int` |
| [notas_traslado_qr_reed_solomon](../xenios-movil/lib/notas_traslado.php#L434) | `function notas_traslado_qr_reed_solomon(array $data, int $eccCount): array` |
| [notas_traslado_qr_append_bits](../xenios-movil/lib/notas_traslado.php#L460) | `function notas_traslado_qr_append_bits(array &$bits, int $value, int $length): void` |
| [notas_traslado_qr_set](../xenios-movil/lib/notas_traslado.php#L467) | `function notas_traslado_qr_set(array &$matrix, array &$reserved, int $row, int $col, bool $dark): void` |
| [notas_traslado_qr_finder](../xenios-movil/lib/notas_traslado.php#L473) | `function notas_traslado_qr_finder(array &$matrix, array &$reserved, int $row, int $col): void` |
| [notas_traslado_qr_alignment](../xenios-movil/lib/notas_traslado.php#L489) | `function notas_traslado_qr_alignment(array &$matrix, array &$reserved, int $row, int $col): void` |
| [notas_traslado_qr_format_bits](../xenios-movil/lib/notas_traslado.php#L499) | `function notas_traslado_qr_format_bits(): int` |
| [notas_traslado_qr_matrix](../xenios-movil/lib/notas_traslado.php#L513) | `function notas_traslado_qr_matrix(string $text): array` |
| [notas_traslado_pdf_qr](../xenios-movil/lib/notas_traslado.php#L635) | `function notas_traslado_pdf_qr(string &$content, float $x, float $y, string $text, float $module = 3.0): void` |
| [notas_traslado_pdf_summary_box](../xenios-movil/lib/notas_traslado.php#L647) | `function notas_traslado_pdf_summary_box(string &$content, float $x, float $y, float $w, string $title, string $value): void` |
| [notas_traslado_pdf_packages_box](../xenios-movil/lib/notas_traslado.php#L654) | `function notas_traslado_pdf_packages_box(string &$content, float $x, float $y, float $w, int $cantidad): void` |
| [notas_traslado_marca_empresa](../xenios-movil/lib/notas_traslado.php#L661) | `function notas_traslado_marca_empresa(PDO $pdo, array $nota): ?array` |
| [notas_traslado_pdf_marca_marker](../xenios-movil/lib/notas_traslado.php#L684) | `function notas_traslado_pdf_marca_marker(?array $marca): string` |
| [notas_traslado_pdf_build](../xenios-movil/lib/notas_traslado.php#L692) | `function notas_traslado_pdf_build(array $nota, array $detalles, ?array $marca = null): string` |
| [notas_traslado_map_row](../xenios-movil/lib/notas_traslado.php#L866) | `function notas_traslado_map_row(array $row, array $context = []): array` |
| [notas_traslado_registrar_traza](../xenios-movil/lib/notas_traslado.php#L933) | `function notas_traslado_registrar_traza(PDO $pdo, string $notaId, string $accion, ?int $statusId, string $detalle, string $usuarioId): void` |
| [notas_traslado_registrar_log](../xenios-movil/lib/notas_traslado.php#L948) | `function notas_traslado_registrar_log(PDO $pdo, string $usuarioId, string $detalle): void` |
| [notas_traslado_registrar_notificacion](../xenios-movil/lib/notas_traslado.php#L963) | `function notas_traslado_registrar_notificacion(PDO $pdo, string $usuarioId, string $titulo, string $mensaje, string $tipo = 'info', ?string $urlDestino = null): void` |
| [notas_traslado_solicitar_regeneracion_pdf_web](../xenios-movil/lib/notas_traslado.php#L982) | `function notas_traslado_solicitar_regeneracion_pdf_web(array $config, string $notaId): bool` |
| [notas_traslado_notificar_actualizacion_web](../xenios-movil/lib/notas_traslado.php#L1047) | `function notas_traslado_notificar_actualizacion_web(array $config, string $notaId): void` |
| [notas_traslado_obtener_pdf_blob](../xenios-movil/lib/notas_traslado.php#L1086) | `function notas_traslado_obtener_pdf_blob(PDO $pdo, string $notaId): string` |
| [notas_traslado_generar_pdf_api](../xenios-movil/lib/notas_traslado.php#L1093) | `function notas_traslado_generar_pdf_api(PDO $pdo, string $notaId): string` |
| [notas_traslado_regenerar_y_obtener_pdf](../xenios-movil/lib/notas_traslado.php#L1124) | `function notas_traslado_regenerar_y_obtener_pdf(PDO $pdo, array $config, string $notaId): string` |
| [notas_traslado_actualizar_estado_y_traza](../xenios-movil/lib/notas_traslado.php#L1129) | `function notas_traslado_actualizar_estado_y_traza(` |
| [notas_traslado_base_select](../xenios-movil/lib/notas_traslado.php#L1191) | `function notas_traslado_base_select(): string` |
| [notas_traslado_puede_ver](../xenios-movil/lib/notas_traslado.php#L1225) | `function notas_traslado_puede_ver(array $nota, array $roles, string $usuarioId, string $depositoId, string $usuarioMail = ''): bool` |
| [notas_traslado_tiene_tarea_pendiente](../xenios-movil/lib/notas_traslado.php#L1280) | `function notas_traslado_tiene_tarea_pendiente(array $row, array $context): bool` |
| [handle_notas_traslado_list](../xenios-movil/lib/notas_traslado.php#L1305) | `function handle_notas_traslado_list(PDO $pdo, array $config): void` |
| [handle_notas_traslado_empresas_transporte](../xenios-movil/lib/notas_traslado.php#L1428) | `function handle_notas_traslado_empresas_transporte(PDO $pdo, array $config): void` |
| [notas_traslado_empresa_transporte_valida](../xenios-movil/lib/notas_traslado.php#L1452) | `function notas_traslado_empresa_transporte_valida(PDO $pdo, string $empresaId): bool` |
| [handle_notas_traslado_transporte_externo](../xenios-movil/lib/notas_traslado.php#L1468) | `function handle_notas_traslado_transporte_externo(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_scan](../xenios-movil/lib/notas_traslado.php#L1593) | `function handle_notas_traslado_scan(PDO $pdo, array $config): void` |
| [notas_traslado_personal_id_usuario](../xenios-movil/lib/notas_traslado.php#L1760) | `function notas_traslado_personal_id_usuario(PDO $pdo, array $user): string` |
| [handle_notas_traslado_entregado](../xenios-movil/lib/notas_traslado.php#L1788) | `function handle_notas_traslado_entregado(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_recibido](../xenios-movil/lib/notas_traslado.php#L1860) | `function handle_notas_traslado_recibido(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_aprobacion](../xenios-movil/lib/notas_traslado.php#L1926) | `function handle_notas_traslado_aprobacion(PDO $pdo, array $config, string $notaId, bool $aprobar): void` |
| [handle_notas_traslado_regenerar_pdf](../xenios-movil/lib/notas_traslado.php#L1999) | `function handle_notas_traslado_regenerar_pdf(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_documento](../xenios-movil/lib/notas_traslado.php#L2043) | `function handle_notas_traslado_documento(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_documento_firmado](../xenios-movil/lib/notas_traslado.php#L2127) | `function handle_notas_traslado_documento_firmado(PDO $pdo, array $config, string $notaId): void` |
| [handle_notas_traslado_routes](../xenios-movil/lib/notas_traslado.php#L2217) | `function handle_notas_traslado_routes(PDO $pdo, array $config, string $path, string $method): bool` |

## xenios-movil/lib/novedades.php

| Función | Firma |
|---|---|
| [novedades_new_id](../xenios-movil/lib/novedades.php#L5) | `function novedades_new_id(): string` |
| [novedades_required_tables](../xenios-movil/lib/novedades.php#L16) | `function novedades_required_tables(): array` |
| [novedades_missing_tables](../xenios-movil/lib/novedades.php#L22) | `function novedades_missing_tables(PDO $pdo): array` |
| [novedades_tables_exist](../xenios-movil/lib/novedades.php#L35) | `function novedades_tables_exist(PDO $pdo): bool` |
| [novedades_timezone](../xenios-movil/lib/novedades.php#L40) | `function novedades_timezone(): DateTimeZone` |
| [novedades_now_string](../xenios-movil/lib/novedades.php#L45) | `function novedades_now_string(): string` |
| [novedades_resolve_destinatarios](../xenios-movil/lib/novedades.php#L52) | `function novedades_resolve_destinatarios(PDO $pdo, ?array $usuarioIds): array` |
| [novedades_usuario_activo](../xenios-movil/lib/novedades.php#L76) | `function novedades_usuario_activo(PDO $pdo, string $usuarioId): ?array` |
| [novedades_remitente_desde_fila](../xenios-movil/lib/novedades.php#L89) | `function novedades_remitente_desde_fila(array $row): array` |
| [novedades_map_item](../xenios-movil/lib/novedades.php#L110) | `function novedades_map_item(array $row): array` |
| [novedades_crear_y_enviar](../xenios-movil/lib/novedades.php#L129) | `function novedades_crear_y_enviar(` |
| [novedades_tokens_por_usuarios](../xenios-movil/lib/novedades.php#L195) | `function novedades_tokens_por_usuarios(PDO $pdo, array $usuarioIds): array` |
| [novedades_registrar_token](../xenios-movil/lib/novedades.php#L211) | `function novedades_registrar_token(` |
| [novedades_normalizar_plataforma](../xenios-movil/lib/novedades.php#L286) | `function novedades_normalizar_plataforma(?string $plataforma): ?string` |
| [novedades_listar_usuario](../xenios-movil/lib/novedades.php#L312) | `function novedades_listar_usuario(PDO $pdo, string $usuarioId, int $limit = 100): array` |
| [novedades_obtener_para_usuario](../xenios-movil/lib/novedades.php#L331) | `function novedades_obtener_para_usuario(PDO $pdo, string $usuarioId, string $novedadId): ?array` |
| [novedades_contar_no_leidas](../xenios-movil/lib/novedades.php#L347) | `function novedades_contar_no_leidas(PDO $pdo, string $usuarioId): int` |
| [novedades_cancelar_alerta](../xenios-movil/lib/novedades.php#L360) | `function novedades_cancelar_alerta(PDO $pdo, string $usuarioId, string $id, string $tipo): void` |
| [novedades_marcar_leida](../xenios-movil/lib/novedades.php#L377) | `function novedades_marcar_leida(PDO $pdo, string $usuarioId, string $novedadId): bool` |
| [novedades_format_datetime](../xenios-movil/lib/novedades.php#L398) | `function novedades_format_datetime(string $value): string` |

## xenios-movil/lib/novedades_historial.php

| Función | Firma |
|---|---|
| [novedades_historial_visitas_sql](../xenios-movil/lib/novedades_historial.php#L6) | `function novedades_historial_visitas_sql(): string` |
| [novedades_historial_visitas](../xenios-movil/lib/novedades_historial.php#L14) | `function novedades_historial_visitas(PDO $pdo, string $uid, ?string $desde = null, ?string $hasta = null, ?string $transaccion = null): array` |
| [novedades_historial_mes](../xenios-movil/lib/novedades_historial.php#L42) | `function novedades_historial_mes(PDO $pdo, string $uid, int $anio, int $mes): array` |

## xenios-movil/lib/password_reset.php

| Función | Firma |
|---|---|
| [password_reset_config](../xenios-movil/lib/password_reset.php#L5) | `function password_reset_config(array $config): array` |
| [password_reset_find_user_by_mail](../xenios-movil/lib/password_reset.php#L36) | `function password_reset_find_user_by_mail(PDO $pdo, string $mail): ?array` |
| [password_reset_create_token](../xenios-movil/lib/password_reset.php#L47) | `function password_reset_create_token(PDO $pdo, string $usuarioId, int $ttlSeconds): string` |
| [password_reset_clear_token](../xenios-movil/lib/password_reset.php#L68) | `function password_reset_clear_token(PDO $pdo, string $usuarioId): void` |
| [password_reset_build_link](../xenios-movil/lib/password_reset.php#L79) | `function password_reset_build_link(array $prConfig, string $plainToken): string` |
| [password_reset_send_mail](../xenios-movil/lib/password_reset.php#L86) | `function password_reset_send_mail(array $prConfig, string $toMail, string $nombre, string $link): bool` |
| [password_reset_send_smtp](../xenios-movil/lib/password_reset.php#L103) | `function password_reset_send_smtp(` |
| [password_reset_build_mime_message](../xenios-movil/lib/password_reset.php#L172) | `function password_reset_build_mime_message(` |
| [password_reset_build_html_body](../xenios-movil/lib/password_reset.php#L225) | `function password_reset_build_html_body(string $textBody, ?string $logoCid): string` |
| [password_reset_format_mailbox](../xenios-movil/lib/password_reset.php#L255) | `function password_reset_format_mailbox(string $mail, string $name): string` |
| [password_reset_dot_stuff](../xenios-movil/lib/password_reset.php#L262) | `function password_reset_dot_stuff(string $body): string` |
| [password_reset_smtp_hostname](../xenios-movil/lib/password_reset.php#L270) | `function password_reset_smtp_hostname(): string` |
| [password_reset_smtp_command](../xenios-movil/lib/password_reset.php#L279) | `function password_reset_smtp_command($socket, string $command, array $expectedCodes): string` |
| [password_reset_smtp_write](../xenios-movil/lib/password_reset.php#L287) | `function password_reset_smtp_write($socket, string $line): void` |
| [password_reset_smtp_expect](../xenios-movil/lib/password_reset.php#L295) | `function password_reset_smtp_expect($socket, array $expectedCodes): string` |
| [password_reset_request](../xenios-movil/lib/password_reset.php#L322) | `function password_reset_request(PDO $pdo, array $config, string $correo): array` |
| [password_reset_validate](../xenios-movil/lib/password_reset.php#L349) | `function password_reset_validate(PDO $pdo, string $plainToken): array` |
| [password_reset_complete](../xenios-movil/lib/password_reset.php#L381) | `function password_reset_complete(PDO $pdo, array $config, string $plainToken, string $nueva, string $confirmacion): array` |

## xenios-movil/lib/push_native.php

| Función | Firma |
|---|---|
| [push_native_config](../xenios-movil/lib/push_native.php#L3) | `function push_native_config(): array` |
| [push_native_text_cut](../xenios-movil/lib/push_native.php#L15) | `function push_native_text_cut(string $value, int $maxBytes): string` |
| [push_native_es_android](../xenios-movil/lib/push_native.php#L22) | `function push_native_es_android(string $plataforma): bool` |
| [push_native_token_valido](../xenios-movil/lib/push_native.php#L27) | `function push_native_token_valido(string $token, string $plataforma): bool` |
| [push_native_ca_bundle](../xenios-movil/lib/push_native.php#L48) | `function push_native_ca_bundle(): ?string` |
| [push_native_build_novedad_messages](../xenios-movil/lib/push_native.php#L72) | `function push_native_build_novedad_messages(` |
| [push_native_build_fichada_messages](../xenios-movil/lib/push_native.php#L113) | `function push_native_build_fichada_messages(array $items): array` |
| [push_native_send_batch](../xenios-movil/lib/push_native.php#L148) | `function push_native_send_batch(array $messages): array` |
| [push_http_post](../xenios-movil/lib/push_native.php#L200) | `function push_http_post(string $url, array $headers, string $body, bool $http2 = false): array` |
| [push_base64url_encode](../xenios-movil/lib/push_native.php#L254) | `function push_base64url_encode(string $value): string` |
| [push_fcm_credentials](../xenios-movil/lib/push_native.php#L261) | `function push_fcm_credentials(): array` |
| [push_fcm_build_payload](../xenios-movil/lib/push_native.php#L339) | `function push_fcm_build_payload(array $message): array` |
| [push_fcm_send](../xenios-movil/lib/push_native.php#L408) | `function push_fcm_send(array $message): array` |
| [push_der_read_length](../xenios-movil/lib/push_native.php#L449) | `function push_der_read_length(string $der, int &$offset): int` |
| [push_ecdsa_der_to_jose](../xenios-movil/lib/push_native.php#L472) | `function push_ecdsa_der_to_jose(string $der, int $partLength = 32): string` |
| [push_apns_credentials](../xenios-movil/lib/push_native.php#L500) | `function push_apns_credentials(): array` |
| [push_apns_send](../xenios-movil/lib/push_native.php#L554) | `function push_apns_send(array $message): array` |

## xenios-movil/lib/registro_personal_jornadas.php

| Función | Firma |
|---|---|
| [registro_personal_fecha_laboral](../xenios-movil/lib/registro_personal_jornadas.php#L5) | `function registro_personal_fecha_laboral(DateTimeImmutable $fecha, ?string $ingreso, ?string $salida): string` |
| [registro_personal_minutos_hora](../xenios-movil/lib/registro_personal_jornadas.php#L17) | `function registro_personal_minutos_hora(string $hora): float` |
| [registro_personal_agrupar_jornadas](../xenios-movil/lib/registro_personal_jornadas.php#L25) | `function registro_personal_agrupar_jornadas(array $fichadas, ?string $ingreso, ?string $salida): array` |

## xenios-movil/lib/reportes_xenios.php

| Función | Firma |
|---|---|
| [reportes_xenios_parse_fecha](../xenios-movil/lib/reportes_xenios.php#L6) | `function reportes_xenios_parse_fecha(string $valor): ?DateTimeImmutable` |
| [reportes_xenios_bool](../xenios-movil/lib/reportes_xenios.php#L13) | `function reportes_xenios_bool(mixed $value): bool` |
| [reportes_xenios_area_ids_desde_query](../xenios-movil/lib/reportes_xenios.php#L20) | `function reportes_xenios_area_ids_desde_query(): array` |
| [reportes_xenios_empleados](../xenios-movil/lib/reportes_xenios.php#L31) | `function reportes_xenios_empleados(` |
| [reportes_xenios_feriados](../xenios-movil/lib/reportes_xenios.php#L83) | `function reportes_xenios_feriados(PDO $pdo, DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [reportes_xenios_razones_ausencia](../xenios-movil/lib/reportes_xenios.php#L100) | `function reportes_xenios_razones_ausencia(PDO $pdo, string $personalId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [reportes_xenios_asistencia](../xenios-movil/lib/reportes_xenios.php#L125) | `function reportes_xenios_asistencia(PDO $pdo, int $year, int $month, ?string $sedeId, array $areaIds): array` |
| [reportes_xenios_fichadas_detalle](../xenios-movil/lib/reportes_xenios.php#L173) | `function reportes_xenios_fichadas_detalle(PDO $pdo, string $legajo, DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [reportes_xenios_auth_detalle](../xenios-movil/lib/reportes_xenios.php#L214) | `function reportes_xenios_auth_detalle(PDO $pdo, string $personalId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array` |
| [reportes_xenios_redondear_hora_arriba](../xenios-movil/lib/reportes_xenios.php#L246) | `function reportes_xenios_redondear_hora_arriba(string $hora): string` |
| [reportes_xenios_asistencia_detallada](../xenios-movil/lib/reportes_xenios.php#L259) | `function reportes_xenios_asistencia_detallada(PDO $pdo, int $year, int $month, ?string $sedeId, array $areaIds): array` |
| [reportes_xenios_horas_trabajadas](../xenios-movil/lib/reportes_xenios.php#L373) | `function reportes_xenios_horas_trabajadas(` |

## xenios-movil/lib/response.php

| Función | Firma |
|---|---|
| [apply_cors](../xenios-movil/lib/response.php#L6) | `function apply_cors(array $config): void` |
| [json_response](../xenios-movil/lib/response.php#L21) | `function json_response(array $data, int $code = 200, ?array $config = null): void` |

## xenios-movil/lib/usuarios.php

| Función | Firma |
|---|---|
| [usuarios_listar_activos](../xenios-movil/lib/usuarios.php#L5) | `function usuarios_listar_activos(PDO $pdo): array` |

## xenios-movil/lib/vacaciones.php

| Función | Firma |
|---|---|
| [vacaciones_contar_dias_periodo](../xenios-movil/lib/vacaciones.php#L19) | `function vacaciones_contar_dias_periodo(DateTimeImmutable $desde, DateTimeImmutable $hasta): int` |
| [vacaciones_dias_ley_por_antiguedad](../xenios-movil/lib/vacaciones.php#L35) | `function vacaciones_dias_ley_por_antiguedad(int $anosCompletos): int` |
| [vacaciones_anos_antiguedad](../xenios-movil/lib/vacaciones.php#L49) | `function vacaciones_anos_antiguedad(DateTimeImmutable $fechaAlta, DateTimeImmutable $referencia): int` |
| [vacaciones_meses_en_anio](../xenios-movil/lib/vacaciones.php#L61) | `function vacaciones_meses_en_anio(` |
| [vacaciones_dias_correspondientes](../xenios-movil/lib/vacaciones.php#L88) | `function vacaciones_dias_correspondientes(` |
| [vacaciones_anios_disponibles](../xenios-movil/lib/vacaciones.php#L117) | `function vacaciones_anios_disponibles(DateTimeImmutable $fechaAlta): array` |
| [vacaciones_fetch_consumidas](../xenios-movil/lib/vacaciones.php#L142) | `function vacaciones_fetch_consumidas(PDO $pdo, string $personalId, int $anio): array` |
| [vacaciones_build_response](../xenios-movil/lib/vacaciones.php#L202) | `function vacaciones_build_response(PDO $pdo, array $personal, int $anio): array` |
| [fetch_personal_vacaciones](../xenios-movil/lib/vacaciones.php#L246) | `function fetch_personal_vacaciones(PDO $pdo, string $mail): ?array` |

## xenios-movil/tools/diagnosticar_rendimiento.php

| Función | Firma |
|---|---|
| [medir_bloque](../xenios-movil/tools/diagnosticar_rendimiento.php#L27) | `function medir_bloque(array &$timings, string $nombre, callable $callback)` |

## xenios-movil/tools/test_calculo_api_horas.php

| Función | Firma |
|---|---|
| [comprobar_calculo](../xenios-movil/tools/test_calculo_api_horas.php#L4) | `function comprobar_calculo(bool $ok, string $caso): void {` |

## xenios-movil/tools/test_dashboard_pendientes.php

| Función | Firma |
|---|---|
| [check_pending](../xenios-movil/tools/test_dashboard_pendientes.php#L6) | `function check_pending(bool $ok, string $label): void {` |

## xenios-movil/tools/test_doble_factor.php

| Función | Firma |
|---|---|
| [comprobar](../xenios-movil/tools/test_doble_factor.php#L4) | `function comprobar(bool $condicion, string $nombre): void` |

## xenios-movil/tools/test_empresa_logo.php

| Función | Firma |
|---|---|
| [logo_check](../xenios-movil/tools/test_empresa_logo.php#L7) | `function logo_check(bool $condition, string $label): void` |

## xenios-movil/tools/test_horas_nocturnas_periodo.php

| Función | Firma |
|---|---|
| [verificar_nocturno](../xenios-movil/tools/test_horas_nocturnas_periodo.php#L7) | `function verificar_nocturno(bool $ok,string $caso): void {` |

## xenios-movil/tools/test_horas_trabajadas_feriados.php

| Función | Firma |
|---|---|
| [verificar_feriados](../xenios-movil/tools/test_horas_trabajadas_feriados.php#L5) | `function verificar_feriados(bool $condicion, string $nombre): void` |

## xenios-movil/tools/test_inout_seguridad.php

| Función | Firma |
|---|---|
| [hora_inout_prueba](../xenios-movil/tools/test_inout_seguridad.php#L14) | `function hora_inout_prueba(string $hora): string {` |
| [check_inout](../xenios-movil/tools/test_inout_seguridad.php#L21) | `function check_inout(bool $condition, string $label): void {` |
| [http_inout](../xenios-movil/tools/test_inout_seguridad.php#L26) | `function http_inout(string $path, ?string $token, string $method = 'GET', ?array $body = null, bool $apiKey = true, ?string $webUserId = null, bool $validSignature = true): array {` |
| [fixture_user](../xenios-movil/tools/test_inout_seguridad.php#L45) | `function fixture_user(string $nombre, string $sede, ?int $role): array {` |

## xenios-movil/tools/test_push_visitas.php

| Función | Firma |
|---|---|
| [check_push_visita](../xenios-movil/tools/test_push_visitas.php#L6) | `function check_push_visita(bool $ok, string $name): void {` |

## xenios-movil/tools/test_registro_personal_jornadas.php

| Función | Firma |
|---|---|
| [comprobar_jornada](../xenios-movil/tools/test_registro_personal_jornadas.php#L4) | `function comprobar_jornada(bool $ok,string $caso): void {if(!$ok)throw new RuntimeException('Fallo: '.$caso);}` |
| [marcas_jornada](../xenios-movil/tools/test_registro_personal_jornadas.php#L5) | `function marcas_jornada(array $fechas): array {return array_map(static fn(string $f): array=>['Fecha'=>new DateTimeImmutable($f),'EsCargaManual'=>str_contains($f,'00:43')],$fechas);}` |
