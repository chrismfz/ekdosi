object FrmMailInvoices: TFrmMailInvoices
  Left = 0
  Top = 0
  BorderStyle = bsNone
  Caption = 'FrmMailInvoices'
  ClientHeight = 216
  ClientWidth = 296
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  OldCreateOrder = False
  Position = poMainFormCenter
  Visible = True
  OnShow = FormShow
  PixelsPerInch = 96
  TextHeight = 13
  object cmdSend: TcxButton
    Left = 94
    Top = 38
    Width = 75
    Height = 25
    Caption = 'cmdSend'
    TabOrder = 0
    Visible = False
  end
  object Progress: TcxProgressBar
    Left = 1
    Top = 1
    ParentFont = False
    Properties.AnimationPath = cxapPingPong
    Properties.AnimationSpeed = 2
    Properties.BeginColor = 8421631
    Properties.Marquee = True
    Properties.Text = 'Sending mail'
    Style.BorderStyle = ebsUltraFlat
    Style.Font.Charset = DEFAULT_CHARSET
    Style.Font.Color = clWindowText
    Style.Font.Height = -16
    Style.Font.Name = 'Arial'
    Style.Font.Style = []
    Style.IsFontAssigned = True
    TabOrder = 1
    Width = 208
  end
  object cmdAbort: TcxButton
    Left = 215
    Top = 1
    Width = 75
    Height = 26
    Caption = 'Abort'
    TabOrder = 2
    OnClick = cmdAbortClick
  end
  object smtp: TIdSMTP
    IOHandler = IdSSLIOHandlerSocketOpenSSL1
    HeloName = 'myip.gr'
    Host = 'mail.myip.gr'
    Password = '4kty6hgowos1'
    Port = 587
    SASLMechanisms = <
      item
      end>
    UseTLS = utUseRequireTLS
    Username = 'invoice@myip.gr'
    Left = 48
    Top = 8
  end
  object Timer: TJvTimer
    EventTime = tetPost
    Interval = 2000
    OnTimer = TimerTimer
    Left = 16
    Top = 8
  end
  object IdSSLIOHandlerSocketOpenSSL1: TIdSSLIOHandlerSocketOpenSSL
    Destination = 'mail.myip.gr:587'
    Host = 'mail.myip.gr'
    MaxLineAction = maException
    Port = 587
    DefaultPort = 0
    SSLOptions.Method = sslvSSLv23
    SSLOptions.SSLVersions = [sslvSSLv2, sslvSSLv3, sslvTLSv1, sslvTLSv1_1, sslvTLSv1_2]
    SSLOptions.Mode = sslmUnassigned
    SSLOptions.VerifyMode = []
    SSLOptions.VerifyDepth = 0
    Left = 168
    Top = 96
  end
end
