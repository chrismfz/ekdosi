object FrmDBParams: TFrmDBParams
  Left = 382
  Top = 115
  BorderIcons = [biSystemMenu, biMinimize]
  BorderStyle = bsSingle
  Caption = 'FrmDBParams'
  ClientHeight = 268
  ClientWidth = 587
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'MS Sans Serif'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Position = poDefault
  ShowHint = True
  Visible = True
  OnClose = FormClose
  OnCreate = FormCreate
  DesignSize = (
    587
    268)
  PixelsPerInch = 96
  TextHeight = 13
  object StatusBar1: TStatusBar
    Left = 0
    Top = 249
    Width = 587
    Height = 19
    AutoHint = True
    Panels = <
      item
        Width = 50
      end>
    ParentShowHint = False
    ShowHint = False
  end
  object cmdOk: TJvDotNetButton
    Left = 400
    Top = 222
    Width = 75
    Height = 21
    Hint = #913#960#959#948#959#967#942' '#945#955#955#945#947#974#957
    Anchors = [akRight, akBottom]
    Caption = 'OK'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Arial'
    Font.Style = []
    ParentFont = False
    ParentShowHint = False
    ShowHint = True
    TabOrder = 1
    OnClick = cmdOkClick
  end
  object cmdCancel: TJvDotNetButton
    Left = 504
    Top = 222
    Width = 75
    Height = 21
    Hint = #913#954#973#961#969#963#951' '#945#955#955#945#947#974#957
    Anchors = [akRight, akBottom]
    Caption = #902#954#965#961#959
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Arial'
    Font.Style = []
    ParentFont = False
    TabOrder = 2
    OnClick = cmdOkClick
  end
  object PanelMain: TJvPanel
    Left = 0
    Top = 0
    Width = 587
    Height = 217
    Align = alTop
    Anchors = [akLeft, akTop, akRight, akBottom]
    BevelInner = bvLowered
    TabOrder = 3
    object TabParameters: TJvPageControl
      Left = 2
      Top = 2
      Width = 583
      Height = 213
      ActivePage = TabSheet3
      Align = alClient
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clWindowText
      Font.Height = -12
      Font.Name = 'Tahoma'
      Font.Style = []
      ParentFont = False
      Style = tsFlatButtons
      TabOrder = 0
      object SheetDatabase: TTabSheet
        Caption = #931#973#957#948#949#963#951
        object LblPasswd: TLabel
          Left = 14
          Top = 119
          Width = 55
          Height = 16
          Caption = 'Password'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object LblUsername: TLabel
          Left = 11
          Top = 91
          Width = 58
          Height = 16
          Caption = 'Username'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object LblPath: TLabel
          Left = 8
          Top = 40
          Width = 27
          Height = 16
          Caption = 'Path'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
        end
        object LblHostname: TLabel
          Left = 8
          Top = 0
          Width = 57
          Height = 16
          Caption = 'Hostname'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object cmdTest: TJvDotNetButton
          Left = 200
          Top = 116
          Width = 75
          Height = 21
          Hint = #916#959#954#953#956#942' '#963#973#957#948#949#963#951#962' '#956#949' '#964#951#957' '#946#940#963#951
          Caption = 'Test'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 4
          OnClick = cmdTestClick
        end
        object EditUsername: TJvDotNetEdit
          Left = 72
          Top = 88
          Width = 113
          Height = 23
          Hint = #972#957#959#956#945' '#967#961#942#963#964#951
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 2
          Text = ''
        end
        object EditPath: TJvDotNetEdit
          Left = 8
          Top = 56
          Width = 265
          Height = 23
          Hint = #948#953#945#948#961#959#956#942' '#945#961#967#949#943#959#965' '#942' alias'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 1
          Text = ''
        end
        object EditPassword: TJvDotNetEdit
          Left = 72
          Top = 114
          Width = 113
          Height = 23
          Hint = #954#969#948#953#954#972#962' '#967#961#942#963#964#951
          ProtectPassword = True
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 3
          Text = ''
        end
        object EditHostname: TJvDotNetEdit
          Left = 8
          Top = 16
          Width = 265
          Height = 23
          Hint = 'Hostname '#942' '#948#953#949#973#952#965#957#963#951' IP'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 0
          Text = ''
        end
      end
      object SheetColors: TTabSheet
        Caption = #935#961#974#956#945#964#945
        ImageIndex = 1
        ExplicitLeft = 0
        ExplicitTop = 0
        ExplicitWidth = 0
        ExplicitHeight = 0
        object LblPriCol: TLabel
          Left = 33
          Top = 19
          Width = 56
          Height = 16
          Alignment = taRightJustify
          Caption = #928#961#969#964#949#973#959#957
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object LblSelCol: TLabel
          Left = 28
          Top = 83
          Width = 61
          Height = 16
          Alignment = taRightJustify
          Caption = #917#960#953#955#949#947#956#941#957#959
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object LblSecCol: TLabel
          Left = 23
          Top = 51
          Width = 66
          Height = 16
          Alignment = taRightJustify
          Caption = #916#949#965#964#949#961#949#973#959#957
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object PanelSelected: TJvPanel
          Left = 96
          Top = 80
          Width = 65
          Height = 25
          Hint = #935#961#974#956#945' '#947#953#945' '#964#951#957' '#960#961#959#946#959#955#942' '#964#951#957' '#949#960#953#955#949#947#956#941#957#951#962' '#949#947#947#961#945#966#942#962
          DotNetHighlighting = True
          TabOrder = 0
          OnMouseDown = PanelMouseDown
          OnMouseUp = PanelMouseUp
        end
        object PanelSecondary: TJvPanel
          Left = 96
          Top = 48
          Width = 65
          Height = 25
          Hint = #916#949#965#964#949#961#941#965#959#957' '#967#961#974#956#945' '#947#953#945' '#964#951#957' '#960#961#959#946#959#955#942' '#948#949#948#959#956#941#957#969#957
          DotNetHighlighting = True
          TabOrder = 1
          OnMouseDown = PanelMouseDown
          OnMouseUp = PanelMouseUp
        end
        object PanelPrimary: TJvPanel
          Left = 96
          Top = 16
          Width = 65
          Height = 25
          Hint = #928#961#969#964#949#973#959#957' '#967#961#974#956#945' '#947#953#945' '#964#951#957' '#960#961#959#946#959#955#942' '#948#949#948#959#956#941#957#969#957
          DotNetHighlighting = True
          TabOrder = 2
          OnMouseDown = PanelMouseDown
          OnMouseUp = PanelMouseUp
        end
      end
      object TabSheet1: TTabSheet
        Caption = #917#954#964#965#960#969#964#942#962
        ImageIndex = 2
        object Label2: TLabel
          Left = 8
          Top = 9
          Width = 131
          Height = 16
          Alignment = taRightJustify
          Caption = #917#960#953#955#949#947#956#941#957#959#962' '#949#954#964#965#960#969#964#942#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label10: TLabel
          Left = 3
          Top = 97
          Width = 500
          Height = 56
          AutoSize = False
          Caption = 
            #928#961#959#963#959#967#942': '#913#965#964#972#962' '#959' '#949#954#964#965#960#969#964#942#962#949#960#953#955#941#947#949#964#945#953' '#949#966#39' '#972#963#959#957' '#948#949#957' '#941#967#949#953' '#959#961#953#963#964#949#943' '#963 +
            #965#947#954#949#954#961#953#956#941#957#959#962' '#949#954#964#965#960#969#964#951#962' '#947#953#945' '#964#951#957' '#949#954#964#973#960#969#963#951' '#964#959#965' '#960#945#961#945#963#964#953#954#959#973' '#942' '#964#951#962' '#945#957#945 +
            #966#959#961#940#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clRed
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          WordWrap = True
        end
        object JvDotNetButton1: TJvDotNetButton
          Left = 226
          Top = 53
          Width = 115
          Height = 25
          Caption = #917#960#953#955#959#947#942' '#949#954#964#965#960#969#964#942
          TabOrder = 0
          OnClick = JvDotNetButton1Click
        end
        object editPrinter: TJvDotNetEdit
          Left = 3
          Top = 25
          Width = 338
          Height = 22
          TabOrder = 1
          Text = 'editPrinter'
        end
        object checkDotMatrix: TJvCheckBox
          Left = 3
          Top = 57
          Width = 118
          Height = 17
          Caption = 'Dot matrix printer'
          TabOrder = 2
          LinkedControls = <>
        end
      end
      object TabSheet2: TTabSheet
        Caption = #915#949#957#953#954#941#962' '#949#960#953#955#959#947#941#962
        ImageIndex = 3
        object Label1: TLabel
          Left = 3
          Top = 16
          Width = 147
          Height = 16
          Caption = #904#955#949#947#967#959#962' '#945#960#959#952#941#956#945#964#959#962' '#945#960#959' '
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label9: TLabel
          Left = 38
          Top = 44
          Width = 200
          Height = 16
          Caption = #932#953#956#941#962' '#956#949' '#934#928#913' '#963#964#959' '#957#941#959' '#960#945#961#945#963#964#945#964#953#954#972
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label13: TLabel
          Left = 80
          Top = 88
          Width = 223
          Height = 16
          Caption = #922#945#952#959#961#953#963#956#972#962' '#966#945#954#941#955#959#965' '#945#960#959#952#942#954#949#965#963#951#962' PDF'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object comboSelReserve: TJvComboBox
          Left = 156
          Top = 14
          Width = 189
          Height = 22
          TabOrder = 0
          Text = ''
          Items.Strings = (
            #913#960#959#952#942#954#951
            #931#965#947#954#949#957#964#961#969#964#953#954#972' '#948#949#955#964#943#959' '#945#960#959#963#964#959#955#942#962
            #925#945' '#956#951#957' '#947#943#957#949#964#945#953)
        end
        object comboGridPricesWVat: TJvComboBox
          Left = 244
          Top = 42
          Width = 101
          Height = 22
          TabOrder = 1
          Text = ''
          Items.Strings = (
            #925#945#953
            #908#967#953)
        end
        object checkPdfExport: TcxCheckBox
          Left = 76
          Top = 66
          Caption = #917#954#964#973#960#969#963#951' '#945#960#39' '#949#965#952#949#943#945#962' '#963#949' pdf  '
          Properties.Alignment = taRightJustify
          TabOrder = 2
        end
        object editDirExportPdf: TJvDirectoryEdit
          Left = 80
          Top = 104
          Width = 265
          Height = 22
          TabOrder = 3
          Text = 'editDirExportPdf'
        end
      end
      object TabBullZip: TTabSheet
        Caption = 'Bullzip PDF'
        ImageIndex = 4
        object Label3: TLabel
          Left = 16
          Top = 40
          Width = 202
          Height = 16
          Caption = #922#945#952#959#961#953#963#956#972#962' '#966#945#954#941#955#959#965' '#949#947#954#945#964#940#963#964#945#963#951#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label4: TLabel
          Left = 16
          Top = 88
          Width = 223
          Height = 16
          Caption = #922#945#952#959#961#953#963#956#972#962' '#966#945#954#941#955#959#965' '#945#960#959#952#942#954#949#965#963#951#962' PDF'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object editDirPdfInstallation: TJvDirectoryEdit
          Left = 16
          Top = 56
          Width = 265
          Height = 22
          TabOrder = 0
          Text = 'editDirPdfInstallation'
        end
        object editDirPdfSave: TJvDirectoryEdit
          Left = 16
          Top = 104
          Width = 265
          Height = 22
          TabOrder = 1
          Text = 'JvDirectoryEdit1'
        end
        object checkBullZip: TJvCheckBox
          Left = 16
          Top = 17
          Width = 96
          Height = 17
          Caption = #917#957#949#961#947#959#960#959#943#951#963#951
          TabOrder = 2
          OnClick = checkBullZipClick
          LinkedControls = <>
        end
      end
      object TabSheet3: TTabSheet
        Caption = 'CS Cart Sync'
        ImageIndex = 5
        object Label5: TLabel
          Left = 22
          Top = 159
          Width = 55
          Height = 16
          Caption = 'Password'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label6: TLabel
          Left = 19
          Top = 131
          Width = 58
          Height = 16
          Caption = 'Username'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label7: TLabel
          Left = 16
          Top = 84
          Width = 91
          Height = 16
          Caption = 'Database name'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
        end
        object Label8: TLabel
          Left = 16
          Top = 40
          Width = 57
          Height = 16
          Caption = 'Hostname'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object checkCsCartSync: TJvCheckBox
          Left = 16
          Top = 17
          Width = 96
          Height = 17
          Caption = #917#957#949#961#947#959#960#959#943#951#963#951
          TabOrder = 0
          OnClick = checkCsCartSyncClick
          LinkedControls = <>
        end
        object cmdMySqlTest: TJvDotNetButton
          Left = 208
          Top = 156
          Width = 75
          Height = 21
          Hint = #916#959#954#953#956#942' '#963#973#957#948#949#963#951#962' '#956#949' '#964#951#957' '#946#940#963#951
          Caption = 'Test'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -13
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 5
          OnClick = cmdMySqlTestClick
        end
        object editMyUsername: TJvDotNetEdit
          Left = 80
          Top = 128
          Width = 113
          Height = 23
          Hint = #972#957#959#956#945' '#967#961#942#963#964#951
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 3
          Text = ''
        end
        object editMyDbName: TJvDotNetEdit
          Left = 16
          Top = 99
          Width = 265
          Height = 23
          Hint = #948#953#945#948#961#959#956#942' '#945#961#967#949#943#959#965' '#942' alias'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 2
          Text = ''
        end
        object editMyPassword: TJvDotNetEdit
          Left = 80
          Top = 154
          Width = 113
          Height = 23
          Hint = #954#969#948#953#954#972#962' '#967#961#942#963#964#951
          ProtectPassword = True
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 4
          Text = ''
        end
        object editMyHostname: TJvDotNetEdit
          Left = 16
          Top = 55
          Width = 265
          Height = 23
          Hint = 'Hostname '#942' '#948#953#949#973#952#965#957#963#951' IP'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = []
          ParentFont = False
          TabOrder = 1
          Text = ''
        end
      end
      object TabSheet4: TTabSheet
        Caption = 'MyDATA'
        ImageIndex = 6
        object Label11: TLabel
          Left = 18
          Top = 24
          Width = 62
          Height = 14
          Caption = #913#913#916#917' User:'
        end
        object Label12: TLabel
          Left = 56
          Top = 57
          Width = 24
          Height = 14
          Caption = 'Key:'
        end
        object lblAFM: TLabel
          Left = 49
          Top = 91
          Width = 31
          Height = 14
          Caption = #913#934#924':'
        end
        object Label14: TLabel
          Left = 11
          Top = 125
          Width = 69
          Height = 14
          Caption = 'Environment'
        end
        object txtAadeUser: TJvDotNetEdit
          Left = 86
          Top = 21
          Width = 258
          Height = 22
          TabOrder = 0
          Text = ''
        end
        object txtAadeKey: TJvDotNetEdit
          Left = 86
          Top = 54
          Width = 258
          Height = 22
          TabOrder = 1
          Text = ''
        end
        object txtAfm: TJvDotNetEdit
          Left = 86
          Top = 88
          Width = 258
          Height = 22
          TabOrder = 2
          Text = ''
        end
        object comboDevEnv: TcxComboBox
          Left = 86
          Top = 121
          ParentFont = False
          Properties.Items.Strings = (
            'Production'
            'Development')
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 3
          Width = 149
        end
      end
    end
  end
  object Database1: TIBDatabase
    LoginPrompt = False
    ServerType = 'IBServer'
    Left = 64
    Top = 224
  end
  object DialogColor: TJvColorDialog
    Left = 104
    Top = 232
  end
  object PrinterDialog: TPrinterSetupDialog
    Left = 136
    Top = 232
  end
  object sqlConnection: TSQLConnection
    ConnectionName = 'MySQLConnection'
    DriverName = 'MySQL'
    LoginPrompt = False
    Params.Strings = (
      'DriverName=MySQL'
      'HostName=ServerName'
      'Database=DBNAME'
      'User_Name=user'
      'Password=password'
      'BlobSize=-1'
      'ErrorResourceFile='
      'LocaleCode=0000'
      'Compressed=False'
      'Encrypted=False')
    Left = 168
    Top = 232
  end
  object ActionList1: TActionList
    Left = 416
    Top = 64
    object Action1: TAction
      Caption = 'Action1'
      ShortCut = 49232
      OnExecute = Action1Execute
    end
  end
end
