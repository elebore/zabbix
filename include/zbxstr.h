/*
** Zabbix
** Copyright (C) 2001-2022 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

#ifndef ZABBIX_STR_H
#define ZABBIX_STR_H

#include "common.h"

char	*string_replace(const char *str, const char *sub_str1, const char *sub_str2);

int	is_boolean(const char *str, zbx_uint64_t *value);
int	is_uoct(const char *str);
int	is_uhex(const char *str);
int	is_hex_string(const char *str);
int	is_ascii_string(const char *str);

int	zbx_rtrim(char *str, const char *charlist);
void	zbx_ltrim(char *str, const char *charlist);
void	zbx_lrtrim(char *str, const char *charlist);
void	zbx_trim_integer(char *str);
void	zbx_trim_float(char *str);
void	zbx_remove_chars(char *str, const char *charlist);
char	*zbx_str_printable_dyn(const char *text);
#define ZBX_WHITESPACE			" \t\r\n"
#define zbx_remove_whitespace(str)	zbx_remove_chars(str, ZBX_WHITESPACE)
void	del_zeros(char *s);

size_t	zbx_get_escape_string_len(const char *src, const char *charlist);
char	*zbx_dyn_escape_string(const char *src, const char *charlist);
int	zbx_escape_string(char *dst, size_t len, const char *src, const char *charlist);

int	str_in_list(const char *list, const char *value, char delimiter);
int	str_n_in_list(const char *list, const char *value, size_t len, char delimiter);
char	*str_linefeed(const char *src, size_t maxline, const char *delim);
void	zbx_strarr_init(char ***arr);
void	zbx_strarr_add(char ***arr, const char *entry);
void	zbx_strarr_free(char ***arr);


void	zbx_strcpy_alloc(char **str, size_t *alloc_len, size_t *offset, const char *src);
void	zbx_chrcpy_alloc(char **str, size_t *alloc_len, size_t *offset, char c);
void	zbx_str_memcpy_alloc(char **str, size_t *alloc_len, size_t *offset, const char *src, size_t n);
void	zbx_strquote_alloc(char **str, size_t *str_alloc, size_t *str_offset, const char *value_str);

void	zbx_strsplit_first(const char *src, char delimiter, char **left, char **right);
void	zbx_strsplit_last(const char *src, char delimiter, char **left, char **right);

/* secure string copy */
#define strscpy(x, y)	zbx_strlcpy(x, y, sizeof(x))
#define strscat(x, y)	zbx_strlcat(x, y, sizeof(x))
void	zbx_strlcat(char *dst, const char *src, size_t siz);
size_t	zbx_strlcpy_utf8(char *dst, const char *src, size_t size);

char	*zbx_strdcat(char *dest, const char *src);
char	*zbx_strdcatf(char *dest, const char *f, ...) __zbx_attr_format_printf(2, 3);

#define VALUE_ERRMSG_MAX	128
const char	*zbx_truncate_itemkey(const char *key, const size_t char_max, char *buf, const size_t buf_len);
const char	*zbx_truncate_value(const char *val, const size_t char_max, char *buf, const size_t buf_len);

const char	*zbx_print_double(char *buffer, size_t size, double val);

/* time related functions */
char	*zbx_age2str(int age);
char	*zbx_date2str(time_t date, const char *tz);
char	*zbx_time2str(time_t time, const char *tz);

#define ZBX_NULL2STR(str)	(NULL != str ? str : "(null)")
#define ZBX_NULL2EMPTY_STR(str)	(NULL != (str) ? (str) : "")

char	*zbx_strcasestr(const char *haystack, const char *needle);
int	cmp_key_id(const char *key_1, const char *key_2);
int	zbx_strncasecmp(const char *s1, const char *s2, size_t n);

const char	*zbx_event_value_string(unsigned char source, unsigned char object, unsigned char value);

#if defined(_WINDOWS) || defined(__MINGW32__)
wchar_t	*zbx_acp_to_unicode(const char *acp_string);
wchar_t	*zbx_oemcp_to_unicode(const char *oemcp_string);
int	zbx_acp_to_unicode_static(const char *acp_string, wchar_t *wide_string, int wide_size);
wchar_t	*zbx_utf8_to_unicode(const char *utf8_string);
char	*zbx_unicode_to_utf8(const wchar_t *wide_string);
char	*zbx_unicode_to_utf8_static(const wchar_t *wide_string, char *utf8_string, int utf8_size);
#endif

void	zbx_strlower(char *str);
void	zbx_strupper(char *str);

#if defined(_WINDOWS) || defined(__MINGW32__) || defined(HAVE_ICONV)
char	*convert_to_utf8(char *in, size_t in_size, const char *encoding);
#endif	/* HAVE_ICONV */

#define ZBX_MAX_BYTES_IN_UTF8_CHAR	4
size_t	zbx_utf8_char_len(const char *text);
size_t	zbx_strlen_utf8(const char *text);
char	*zbx_strshift_utf8(char *text, size_t num);
size_t	zbx_strlen_utf8_nchars(const char *text, size_t utf8_maxlen);
size_t	zbx_charcount_utf8_nbytes(const char *text, size_t maxlen);

int	zbx_is_utf8(const char *text);
void	zbx_replace_invalid_utf8(char *text);
int	zbx_cesu8_to_utf8(const char *cesu8, char **utf8);

void	dos2unix(char *str);
int	str2uint64(const char *str, const char *suffixes, zbx_uint64_t *value);
double	str2double(const char *str);

int	zbx_check_hostname(const char *hostname, char **error);
int	zbx_suffixed_number_parse(const char *number, int *len);

int	zbx_replace_mem_dyn(char **data, size_t *data_alloc, size_t *data_len, size_t offset, size_t sz_to,
		const char *from, size_t sz_from);

void	zbx_trim_str_list(char *list, char delimiter);

int	zbx_strcmp_null(const char *s1, const char *s2);


char	*zbx_dyn_escape_shell_single_quote(const char *arg);

void	zbx_function_param_parse(const char *expr, size_t *param_pos, size_t *length, size_t *sep_pos);
char	*zbx_function_param_unquote_dyn(const char *param, size_t len, int *quoted);
int	zbx_function_param_quote(char **param, int forced);
int	zbx_function_validate_parameters(const char *expr, size_t *length);
int	zbx_function_find(const char *expr, size_t *func_pos, size_t *par_l, size_t *par_r,
		char *error, int max_error_len);
char	*zbx_function_get_param_dyn(const char *params, int Nparam);

int	zbx_strcmp_natural(const char *s1, const char *s2);

int	zbx_strmatch_condition(const char *value, const char *pattern, unsigned char op);

int	zbx_str_extract(const char *text, size_t len, char **value);

char	*zbx_substr(const char *src, size_t left, size_t right);
char	*zbx_substr_unquote(const char *src, size_t left, size_t right);

/* UTF-8 trimming */
void	zbx_ltrim_utf8(char *str, const char *charlist);
void	zbx_rtrim_utf8(char *str, const char *charlist);

#endif /* ZABBIX_STR_H */

