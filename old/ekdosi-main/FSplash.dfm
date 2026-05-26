object FrmSplash: TFrmSplash
  Left = 0
  Top = 0
  BorderStyle = bsNone
  Caption = 'FrmSplash'
  ClientHeight = 261
  ClientWidth = 451
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  KeyPreview = True
  OldCreateOrder = False
  Position = poScreenCenter
  PixelsPerInch = 96
  TextHeight = 13
  object JvPanel1: TJvPanel
    Left = 0
    Top = 0
    Width = 451
    Height = 261
    Align = alClient
    BevelInner = bvLowered
    BorderWidth = 1
    BorderStyle = bsSingle
    TabOrder = 0
    object Label1: TLabel
      Left = 3
      Top = 3
      Width = 441
      Height = 107
      Align = alTop
      Alignment = taCenter
      Caption = #904#954#948#959#963#951
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlue
      Font.Height = -96
      Font.Name = 'Arial'
      Font.Style = []
      ParentFont = False
      ExplicitWidth = 340
    end
    object LabelVersion: TLabel
      Left = 256
      Top = 96
      Width = 54
      Height = 13
      Caption = 'Version 1.0'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clBlack
      Font.Height = -11
      Font.Name = 'Tahoma'
      Font.Style = []
      ParentFont = False
    end
    object Label3: TLabel
      Left = 24
      Top = 152
      Width = 80
      Height = 18
      Caption = 'Username:'
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object Label4: TLabel
      Left = 26
      Top = 180
      Width = 78
      Height = 18
      Caption = 'Password:'
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblWait: TLabel
      Left = 232
      Top = 232
      Width = 199
      Height = 19
      Caption = #928#945#961#945#954#945#955#974' '#960#949#961#953#956#941#957#949#964#949'.....'
      Font.Charset = GREEK_CHARSET
      Font.Color = clRed
      Font.Height = -16
      Font.Name = 'Tahoma'
      Font.Style = [fsBold, fsItalic]
      ParentFont = False
      Visible = False
    end
    object Label2: TLabel
      Left = 12
      Top = 205
      Width = 92
      Height = 18
      Alignment = taRightJustify
      Caption = #919#956#949#961#959#956#951#957#943#945':'
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object editUsername: TJvDotNetEdit
      Left = 104
      Top = 151
      Width = 121
      Height = 21
      Flat = False
      ParentFlat = False
      AutoSize = False
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -13
      Font.Name = 'Arial'
      Font.Style = []
      HideSelection = False
      ParentFont = False
      TabOrder = 0
      Text = ''
      OnKeyPress = editUsernameKeyPress
    end
    object editPassword: TJvDotNetEdit
      Left = 104
      Top = 178
      Width = 121
      Height = 21
      ThemedPassword = True
      AutoSize = False
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -13
      Font.Name = 'Arial'
      Font.Style = []
      ParentFont = False
      TabOrder = 1
      Text = ''
      OnKeyPress = editPasswordKeyPress
    end
    object cmdAuthentication: TJvDotNetButton
      Left = 272
      Top = 152
      Width = 96
      Height = 20
      Caption = #917#960#945#955#942#952#949#965#963#951
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold, fsItalic]
      ParentFont = False
      TabOrder = 3
      OnClick = cmdAuthenticationClick
    end
    object editDate: TJvDateEdit
      Left = 104
      Top = 204
      Width = 121
      Height = 21
      DotNetHighlighting = True
      AutoSize = False
      CalendarHints.Strings = (
        #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945)
      CheckOnExit = True
      DefaultToday = True
      DialogTitle = #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clWindowText
      Font.Height = -11
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
      ShowNullDate = False
      TabOrder = 2
      OnKeyPress = editDateKeyPress
    end
    object labelCounter: TcxLabel
      Left = 3
      Top = 235
      Caption = '0'
    end
  end
  object JvTimer1: TJvTimer
    OnTimer = JvTimer1Timer
    Left = 400
    Top = 168
  end
end
