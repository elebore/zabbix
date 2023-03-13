/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
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

#ifndef ZABBIX_COMMS_H
#define ZABBIX_COMMS_H

#include "config.h"
#include "zbxtime.h"

#ifdef _WINDOWS
#	define ZBX_TCP_WRITE(s, b, bl)		((ssize_t)send((s), (b), (int)(bl), 0))
#	define ZBX_TCP_READ(s, b, bl)		((ssize_t)recv((s), (b), (int)(bl), 0))
#	define zbx_socket_close(s)		if (ZBX_SOCKET_ERROR != (s)) closesocket(s)
#	define zbx_bind(s, a, l)		(bind((s), (a), (int)(l)))
#	define zbx_sendto(fd, b, n, f, a, l)	(sendto((fd), (b), (int)(n), (f), (a), (l)))
#	define ZBX_PROTO_AGAIN			WSAEINTR
#	define ZBX_SOCKET_ERROR			INVALID_SOCKET

typedef struct
{
	SOCKET	fd;
	short	events;
	short	revents;
}
zbx_pollfd_t;

int	tcp_poll(zbx_pollfd_t* fds, int fds_num, int timeout);

#else
#	define ZBX_TCP_WRITE(s, b, bl)		((ssize_t)write((s), (b), (bl)))
#	define ZBX_TCP_READ(s, b, bl)		((ssize_t)read((s), (b), (bl)))
#	define zbx_socket_close(s)		if (ZBX_SOCKET_ERROR != (s)) close(s)
#	define zbx_bind(s, a, l)		(bind((s), (a), (l)))
#	define zbx_sendto(fd, b, n, f, a, l)	(sendto((fd), (b), (n), (f), (a), (l)))
#	define ZBX_PROTO_AGAIN		EINTR
#	define ZBX_SOCKET_ERROR		-1
#	define tcp_poll(x, y, z)	poll(x, y, z)

typedef struct pollfd zbx_pollfd_t;

#endif

void	tcp_get_deadline(zbx_timespec_t *ts, int sec);
int	tcp_check_deadline(const zbx_timespec_t *deadline);

#endif /* ZABBIX_COMMS_H */
